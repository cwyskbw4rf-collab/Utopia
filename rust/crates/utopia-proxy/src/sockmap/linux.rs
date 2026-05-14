//! Linux-only BPF sockmap implementation using `libbpf-rs`.

use std::collections::HashMap;
use std::ffi::OsStr;
use std::os::fd::{AsFd, AsRawFd, RawFd};
use std::path::Path;

use libbpf_rs::{MapCore, MapFlags, Object, ObjectBuilder};

use super::tuple;

/// BPF attach type `BPF_SK_SKB_STREAM_VERDICT` from `<linux/bpf.h>` (enum value 5).
const BPF_SK_SKB_STREAM_VERDICT: u32 = 5;

/// BPF command `BPF_PROG_ATTACH` from `<linux/bpf.h>`.
const BPF_PROG_ATTACH: i64 = 8;

pub struct Inner {
    object: Object,
    peers_fd: RawFd,
    allocated_pairs: HashMap<RawFd, (u64, u64)>,
}

impl Inner {
    pub fn load(path: &Path) -> Result<Self, String> {
        if !path.is_file() {
            return Err(format!("BPF object not found: {}", path.display()));
        }

        let mut builder = ObjectBuilder::default();
        let object = builder
            .open_file(path)
            .map_err(|e| format!("open_file: {e}"))?
            .load()
            .map_err(|e| format!("load: {e}"))?;

        let peers_fd =
            find_map_fd(&object, "peers").ok_or_else(|| "map 'peers' not found".to_string())?;
        let prog_fd = find_prog_fd(&object, "relay")
            .ok_or_else(|| "program 'relay' not found".to_string())?;

        let attach_rc = unsafe {
            let attr = BpfProgAttachAttr {
                target_fd: peers_fd as u32,
                attach_bpf_fd: prog_fd as u32,
                attach_type: BPF_SK_SKB_STREAM_VERDICT,
                attach_flags: 0,
            };
            libc::syscall(
                libc::SYS_bpf,
                BPF_PROG_ATTACH,
                &attr as *const _ as *const libc::c_void,
                std::mem::size_of::<BpfProgAttachAttr>() as u32,
            )
        };
        if attach_rc < 0 {
            return Err(format!(
                "bpf_prog_attach failed: {}",
                std::io::Error::last_os_error()
            ));
        }

        Ok(Self {
            object,
            peers_fd,
            allocated_pairs: HashMap::new(),
        })
    }

    pub fn insert_pair(&mut self, accept_fd: RawFd, backend_fd: RawFd) -> bool {
        let self_key = match build_tuple_key(accept_fd) {
            Some(k) => k,
            None => return false,
        };
        let peer_key = match build_tuple_key(backend_fd) {
            Some(k) => k,
            None => return false,
        };

        let peers = match find_map(&self.object, "peers") {
            Some(m) => m,
            None => return false,
        };

        let self_key_bytes = self_key.to_le_bytes();
        let peer_key_bytes = peer_key.to_le_bytes();
        let backend_val = (backend_fd as u32).to_le_bytes();
        let accept_val = (accept_fd as u32).to_le_bytes();

        if peers
            .update(&self_key_bytes, &backend_val, MapFlags::ANY)
            .is_err()
        {
            return false;
        }
        if peers
            .update(&peer_key_bytes, &accept_val, MapFlags::ANY)
            .is_err()
        {
            let _ = peers.delete(&self_key_bytes);
            return false;
        }

        self.allocated_pairs.insert(accept_fd, (self_key, peer_key));
        true
    }

    pub fn remove_pair(&mut self, accept_fd: RawFd, _backend_fd: RawFd) {
        let keys = match self.allocated_pairs.remove(&accept_fd) {
            Some(k) => k,
            None => return,
        };
        if let Some(peers) = find_map(&self.object, "peers") {
            let _ = peers.delete(&keys.0.to_le_bytes());
            let _ = peers.delete(&keys.1.to_le_bytes());
        }
    }

    pub fn peers_fd(&self) -> RawFd {
        self.peers_fd
    }
}

impl Drop for Inner {
    fn drop(&mut self) {
        let accepts: Vec<RawFd> = self.allocated_pairs.keys().copied().collect();
        for fd in accepts {
            self.remove_pair(fd, 0);
        }
    }
}

#[repr(C)]
struct BpfProgAttachAttr {
    target_fd: u32,
    attach_bpf_fd: u32,
    attach_type: u32,
    attach_flags: u32,
}

fn find_map<'a>(object: &'a Object, name: &str) -> Option<libbpf_rs::Map<'a>> {
    let wanted: &OsStr = OsStr::new(name);
    object.maps().find(|m| m.name() == wanted)
}

fn find_map_fd(object: &Object, name: &str) -> Option<RawFd> {
    find_map(object, name).map(|m| m.as_fd().as_raw_fd())
}

fn find_prog_fd(object: &Object, name: &str) -> Option<RawFd> {
    let wanted: &OsStr = OsStr::new(name);
    object
        .progs()
        .find(|p| p.name() == wanted)
        .map(|p| p.as_fd().as_raw_fd())
}

fn build_tuple_key(fd: RawFd) -> Option<u64> {
    let local = getsockname_inet(fd)?;
    let peer = getpeername_inet(fd)?;

    let local_port = u16::from_be_bytes([local[2], local[3]]);
    if local_port == 0 {
        return None;
    }
    let remote_port_be = [peer[2], peer[3]];
    let remote_ip_be = [peer[4], peer[5], peer[6], peer[7]];

    Some(tuple::tuple_key(local_port, remote_port_be, remote_ip_be))
}

fn getsockname_inet(fd: RawFd) -> Option<[u8; 16]> {
    let mut buf = [0u8; 16];
    let mut len: libc::socklen_t = 16;
    let rc = unsafe {
        libc::getsockname(
            fd,
            buf.as_mut_ptr() as *mut libc::sockaddr,
            &mut len as *mut _,
        )
    };
    if rc < 0 {
        return None;
    }
    Some(buf)
}

fn getpeername_inet(fd: RawFd) -> Option<[u8; 16]> {
    let mut buf = [0u8; 16];
    let mut len: libc::socklen_t = 16;
    let rc = unsafe {
        libc::getpeername(
            fd,
            buf.as_mut_ptr() as *mut libc::sockaddr,
            &mut len as *mut _,
        )
    };
    if rc < 0 {
        return None;
    }
    Some(buf)
}
