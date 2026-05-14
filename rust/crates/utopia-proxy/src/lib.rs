//! utopia-proxy — Rust port of `utopia-php/proxy`.
//!
//! Phase 1: core library modules (protocol, resolver, DNS, adapter, TLS, sockmap).
//! Server implementations (TCP, HTTP, SMTP) land in Phase 2.

pub mod adapter;
pub mod connection_result;
pub mod dns;
pub mod error;
pub mod protocol;
pub mod resolver;
pub mod server;
pub mod sockmap;
pub mod tls;

pub use adapter::tcp::TcpAdapter;
pub use adapter::Adapter;
pub use connection_result::ConnectionResult;
pub use error::ProxyError;
pub use protocol::Protocol;
pub use resolver::fixed::Fixed;
pub use resolver::{Resolver, ResolverError, ResolverResult};
