//! BPF sockmap loader. Mirrors `src/Sockmap/Loader.php`.
//!
//! Linux-only. On other platforms this module exposes an inert stub so callers
//! can keep their code paths identical.

pub mod tuple;

use std::os::fd::RawFd;
use std::path::{Path, PathBuf};

use parking_lot::Mutex;

#[cfg(target_os = "linux")]
mod linux;

/// BPF sockmap zero-copy relay loader.
pub struct Sockmap {
    bpf_object_path: PathBuf,
    state: Mutex<State>,
}

struct State {
    available: bool,
    last_error: String,
    #[cfg(target_os = "linux")]
    inner: Option<linux::Inner>,
}

impl Sockmap {
    pub fn new(bpf_object_path: impl AsRef<Path>) -> Self {
        Self {
            bpf_object_path: bpf_object_path.as_ref().to_path_buf(),
            state: Mutex::new(State {
                available: false,
                last_error: String::new(),
                #[cfg(target_os = "linux")]
                inner: None,
            }),
        }
    }

    pub fn bpf_object_path(&self) -> &Path {
        &self.bpf_object_path
    }

    /// Attempt to load the BPF object and wire maps + program. Returns true on success.
    pub fn load(&self) -> bool {
        #[cfg(target_os = "linux")]
        {
            let mut state = self.state.lock();
            match linux::Inner::load(&self.bpf_object_path) {
                Ok(inner) => {
                    state.available = true;
                    state.last_error.clear();
                    state.inner = Some(inner);
                    true
                }
                Err(e) => {
                    state.available = false;
                    state.last_error = e;
                    false
                }
            }
        }

        #[cfg(not(target_os = "linux"))]
        {
            let mut state = self.state.lock();
            state.available = false;
            state.last_error = "sockmap requires Linux".to_string();
            false
        }
    }

    pub fn is_available(&self) -> bool {
        self.state.lock().available
    }

    pub fn last_error(&self) -> String {
        self.state.lock().last_error.clone()
    }

    /// Insert a (client, backend) fd pair into the sockmap. After success, the
    /// kernel forwards data between the two fds without userspace involvement.
    pub fn insert_pair(&self, accept_fd: RawFd, backend_fd: RawFd) -> bool {
        #[cfg(target_os = "linux")]
        {
            let mut state = self.state.lock();
            if !state.available {
                return false;
            }
            if let Some(inner) = state.inner.as_mut() {
                return inner.insert_pair(accept_fd, backend_fd);
            }
            false
        }

        #[cfg(not(target_os = "linux"))]
        {
            let _ = (accept_fd, backend_fd);
            false
        }
    }

    /// Remove a previously inserted pair.
    pub fn remove_pair(&self, accept_fd: RawFd, backend_fd: RawFd) {
        #[cfg(target_os = "linux")]
        {
            let mut state = self.state.lock();
            if let Some(inner) = state.inner.as_mut() {
                inner.remove_pair(accept_fd, backend_fd);
            }
        }

        #[cfg(not(target_os = "linux"))]
        {
            let _ = (accept_fd, backend_fd);
        }
    }

    /// Release all kernel resources held by the loader.
    pub fn close(&self) {
        #[cfg(target_os = "linux")]
        {
            let mut state = self.state.lock();
            state.inner = None;
            state.available = false;
        }

        #[cfg(not(target_os = "linux"))]
        {
            let mut state = self.state.lock();
            state.available = false;
        }
    }
}

impl Drop for Sockmap {
    fn drop(&mut self) {
        self.close();
    }
}
