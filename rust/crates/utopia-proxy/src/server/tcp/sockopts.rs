//! TCP socket tuning applied to listeners and accepted streams.
//!
//! Linux-only knobs (TCP_FASTOPEN, TCP_DEFER_ACCEPT, TCP_USER_TIMEOUT,
//! TCP_QUICKACK, TCP_NOTSENT_LOWAT) are applied on the appropriate side of the
//! connection. On non-Linux targets these no-op silently — portable knobs
//! (TCP_NODELAY, SO_REUSEPORT, keepalive idle/interval/count) still apply.

use std::io;

use socket2::SockRef;
use tokio::net::{TcpListener, TcpStream};

use super::config::TcpConfig;

/// Apply listener-side options before `accept()` starts. Includes SO_REUSEPORT
/// (Linux), TCP_FASTOPEN (Linux), TCP_DEFER_ACCEPT=5 (Linux), and backlog sizing
/// via the system listen backlog (configured during bind).
pub fn apply_listener(listener: &TcpListener, config: &TcpConfig) -> io::Result<()> {
    let socket = SockRef::from(listener);

    #[cfg(target_os = "linux")]
    {
        use std::os::fd::AsRawFd;

        let fd = listener.as_raw_fd();

        if config.enable_reuse_port {
            let _ = socket.set_reuse_port(true);
        }

        unsafe {
            // TCP_FASTOPEN (23) — allow queue of deferred-accept data on SYN.
            let val: libc::c_int = config.backlog.max(1024);
            libc::setsockopt(
                fd,
                libc::IPPROTO_TCP,
                libc::TCP_FASTOPEN,
                &val as *const _ as *const libc::c_void,
                std::mem::size_of_val(&val) as libc::socklen_t,
            );

            // TCP_DEFER_ACCEPT (9) — wake up only once data arrives.
            let defer: libc::c_int = 5;
            libc::setsockopt(
                fd,
                libc::IPPROTO_TCP,
                libc::TCP_DEFER_ACCEPT,
                &defer as *const _ as *const libc::c_void,
                std::mem::size_of_val(&defer) as libc::socklen_t,
            );
        }
    }

    #[cfg(not(target_os = "linux"))]
    {
        let _ = (listener, config, socket);
    }

    Ok(())
}

/// Apply stream-side options on an accepted client connection.
pub fn apply_stream(stream: &TcpStream, config: &TcpConfig) -> io::Result<()> {
    stream.set_nodelay(true)?;

    let socket = SockRef::from(stream);

    // Bound the kernel per-socket memory. Without this the kernel's auto-tuning
    // grows SO_{SND,RCV}BUF up to ~6 MB each; at 100k+ idle connections that
    // dwarfs everything else. Swoole sets small explicit buffers for the same
    // reason — we mirror that here using socket_buffer_size / buffer_output_size.
    if config.socket_buffer_size > 0 {
        let rcv = config.socket_buffer_size as usize;
        let _ = socket.set_recv_buffer_size(rcv);
    }
    if config.buffer_output_size > 0 {
        let snd = config.buffer_output_size as usize;
        let _ = socket.set_send_buffer_size(snd);
    }

    let keepalive = socket2::TcpKeepalive::new()
        .with_time(std::time::Duration::from_secs(config.tcp_keepidle as u64))
        .with_interval(std::time::Duration::from_secs(
            config.tcp_keepinterval as u64,
        ))
        .with_retries(config.tcp_keepcount);
    let _ = socket.set_tcp_keepalive(&keepalive);

    #[cfg(target_os = "linux")]
    {
        use std::os::fd::AsRawFd;

        let fd = stream.as_raw_fd();
        unsafe {
            if config.tcp_user_timeout_ms > 0 {
                let val: libc::c_int = config.tcp_user_timeout_ms as libc::c_int;
                libc::setsockopt(
                    fd,
                    libc::IPPROTO_TCP,
                    libc::TCP_USER_TIMEOUT,
                    &val as *const _ as *const libc::c_void,
                    std::mem::size_of_val(&val) as libc::socklen_t,
                );
            }
            if config.tcp_quickack {
                let val: libc::c_int = 1;
                libc::setsockopt(
                    fd,
                    libc::IPPROTO_TCP,
                    libc::TCP_QUICKACK,
                    &val as *const _ as *const libc::c_void,
                    std::mem::size_of_val(&val) as libc::socklen_t,
                );
            }
            if config.tcp_notsent_lowat > 0 {
                // TCP_NOTSENT_LOWAT = 25; not in libc constants on older versions.
                let val: libc::c_int = config.tcp_notsent_lowat as libc::c_int;
                libc::setsockopt(
                    fd,
                    libc::IPPROTO_TCP,
                    25,
                    &val as *const _ as *const libc::c_void,
                    std::mem::size_of_val(&val) as libc::socklen_t,
                );
            }
        }
    }

    #[cfg(not(target_os = "linux"))]
    {
        let _ = config;
    }

    Ok(())
}
