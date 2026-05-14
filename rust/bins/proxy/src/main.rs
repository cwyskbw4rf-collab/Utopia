//! utopia-proxy CLI — Rust port of `bin/proxy` (PHP).
//!
//! Subcommands: `tcp`, `http`, `smtp`. Default when no arg given: `tcp`.
//! Env var names match the PHP entrypoint exactly so benchmark harnesses and
//! deployment manifests can target either implementation interchangeably.

use std::env;
use std::path::PathBuf;
use std::process::ExitCode;
use std::sync::Arc;
use std::time::Duration;

use clap::{Parser, Subcommand};
use tokio::signal;
use tracing::{info, warn};
use tracing_subscriber::{fmt, prelude::*, EnvFilter};

use utopia_proxy::server::http::{HttpConfig, HttpServer};
use utopia_proxy::server::smtp::{SmtpConfig, SmtpServer};
use utopia_proxy::server::tcp::{TcpConfig, TcpServer};
use utopia_proxy::tls::Tls;
use utopia_proxy::{Fixed, Resolver};

const DEFAULT_TCP_ENDPOINT: &str = "127.0.0.1:5432";
const DEFAULT_HTTP_ENDPOINT: &str = "127.0.0.1:5678";
const DEFAULT_SMTP_ENDPOINT: &str = "127.0.0.1:1025";
const DEFAULT_RESOLVER_PATH: &str = "/etc/utopia-proxy/resolver.php";

/// utopia-proxy — high-performance protocol-agnostic proxy (Rust edition).
#[derive(Debug, Parser)]
#[command(
    name = "proxy",
    version,
    about = "Utopia Proxy — TCP / HTTP / SMTP proxy (Rust)",
    long_about = "Utopia Proxy — high-performance protocol-agnostic proxy.\n\n\
                  Configuration is driven entirely through environment variables \
                  for parity with the PHP entrypoint (bin/proxy)."
)]
struct Cli {
    #[command(subcommand)]
    command: Option<Command>,
}

#[derive(Debug, Subcommand)]
enum Command {
    /// Start the TCP proxy (PostgreSQL, MySQL, MongoDB, ...).
    Tcp,
    /// Start the HTTP proxy.
    Http,
    /// Start the SMTP proxy.
    Smtp,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Protocol {
    Tcp,
    Http,
    Smtp,
}

impl Protocol {
    fn from_command(command: Option<&Command>) -> Option<Self> {
        command.map(|c| match c {
            Command::Tcp => Self::Tcp,
            Command::Http => Self::Http,
            Command::Smtp => Self::Smtp,
        })
    }

    fn parse_name(name: &str) -> Option<Self> {
        match name.to_ascii_lowercase().as_str() {
            "tcp" => Some(Self::Tcp),
            "http" => Some(Self::Http),
            "smtp" => Some(Self::Smtp),
            _ => None,
        }
    }

    fn endpoint_env(self) -> &'static str {
        match self {
            Self::Tcp => "TCP_BACKEND_ENDPOINT",
            Self::Http => "HTTP_BACKEND_ENDPOINT",
            Self::Smtp => "SMTP_BACKEND_ENDPOINT",
        }
    }

    fn endpoint_default(self) -> &'static str {
        match self {
            Self::Tcp => DEFAULT_TCP_ENDPOINT,
            Self::Http => DEFAULT_HTTP_ENDPOINT,
            Self::Smtp => DEFAULT_SMTP_ENDPOINT,
        }
    }

    fn label(self) -> &'static str {
        match self {
            Self::Tcp => "TCP",
            Self::Http => "HTTP",
            Self::Smtp => "SMTP",
        }
    }
}

fn init_logging() {
    let filter = EnvFilter::try_from_default_env().unwrap_or_else(|_| EnvFilter::new("info"));
    let _ = tracing_subscriber::registry()
        .with(filter)
        .with(fmt::layer().with_target(false))
        .try_init();
}

/// Parse a boolean env var matching PHP's `FILTER_VALIDATE_BOOLEAN`:
/// truthy: `1`, `true`, `yes`, `on` (case-insensitive); everything else → false.
fn env_bool(key: &str, default: bool) -> bool {
    match env::var(key) {
        Ok(value) => match value.trim().to_ascii_lowercase().as_str() {
            "1" | "true" | "yes" | "on" => true,
            "0" | "false" | "no" | "off" | "" => false,
            _ => default,
        },
        Err(_) => default,
    }
}

fn env_usize(key: &str, default: usize) -> usize {
    env::var(key)
        .ok()
        .and_then(|value| value.trim().parse::<usize>().ok())
        .unwrap_or(default)
}

fn env_u16(key: &str, default: u16) -> u16 {
    env::var(key)
        .ok()
        .and_then(|value| value.trim().parse::<u16>().ok())
        .unwrap_or(default)
}

fn env_u64(key: &str, default: u64) -> u64 {
    env::var(key)
        .ok()
        .and_then(|value| value.trim().parse::<u64>().ok())
        .unwrap_or(default)
}

fn env_f64(key: &str, default: f64) -> f64 {
    env::var(key)
        .ok()
        .and_then(|value| value.trim().parse::<f64>().ok())
        .unwrap_or(default)
}

fn env_string(key: &str, default: &str) -> String {
    env::var(key)
        .map(|value| {
            if value.is_empty() {
                default.to_string()
            } else {
                value
            }
        })
        .unwrap_or_else(|_| default.to_string())
}

fn cpus() -> usize {
    std::thread::available_parallelism()
        .map(|n| n.get())
        .unwrap_or(1)
}

fn resolve_protocol(command: Option<&Command>) -> Result<Protocol, String> {
    if let Some(protocol) = Protocol::from_command(command) {
        return Ok(protocol);
    }
    if let Ok(from_env) = env::var("PROXY_PROTOCOL") {
        if !from_env.is_empty() {
            return Protocol::parse_name(&from_env).ok_or_else(|| {
                format!(
                    "Unknown protocol in PROXY_PROTOCOL: {from_env} (expected tcp, http, or smtp)"
                )
            });
        }
    }
    Ok(Protocol::Tcp)
}

fn build_resolver(protocol: Protocol) -> (Arc<dyn Resolver>, String) {
    let resolver_path =
        env::var("PROXY_RESOLVER").unwrap_or_else(|_| DEFAULT_RESOLVER_PATH.to_string());
    if PathBuf::from(&resolver_path).is_file() {
        warn!(
            path = %resolver_path,
            "PROXY_RESOLVER is a PHP file — the Rust proxy cannot load it. Falling back to Fixed resolver."
        );
    }
    let endpoint = env_string(protocol.endpoint_env(), protocol.endpoint_default());
    let description = format!("fixed ({endpoint})");
    (Arc::new(Fixed::new(endpoint)), description)
}

fn build_tls() -> Result<Option<Arc<Tls>>, String> {
    if !env_bool("PROXY_TLS_ENABLED", false) {
        return Ok(None);
    }
    let certificate = env::var("PROXY_TLS_CERT").unwrap_or_default();
    let key = env::var("PROXY_TLS_KEY").unwrap_or_default();
    if certificate.is_empty() || key.is_empty() {
        return Err(
            "PROXY_TLS_ENABLED=true but PROXY_TLS_CERT and PROXY_TLS_KEY are required".to_string(),
        );
    }
    let ca = env::var("PROXY_TLS_CA").unwrap_or_default();
    let require_client_cert = env_bool("PROXY_TLS_REQUIRE_CLIENT_CERT", false);
    let mut tls = Tls::new(certificate, key);
    if !ca.is_empty() {
        tls = tls.with_ca(ca);
    }
    tls = tls.with_client_cert_required(require_client_cert);
    Ok(Some(Arc::new(tls)))
}

fn log_accepted_but_ignored(key: &str, reason: &str) {
    if env::var(key).is_ok() {
        info!(env = key, "{reason}");
    }
}

fn warn_if_set(key: &str) {
    if env::var(key).is_ok() {
        info!(
            env = key,
            "environment variable accepted but ignored in the Rust runtime"
        );
    }
}

fn banner(protocol: Protocol, resolver_description: &str, details: &[(&str, String)]) {
    println!("────────────────────────────────────────");
    println!(" Utopia Proxy (Rust)");
    println!("────────────────────────────────────────");
    println!(" Protocol : {}", protocol.label());
    println!(" Resolver : {}", resolver_description);
    for (key, value) in details {
        println!(" {:<9}: {}", key, value);
    }
    println!("────────────────────────────────────────");
}

async fn run_tcp(resolver: Arc<dyn Resolver>, resolver_description: &str) -> Result<(), String> {
    log_accepted_but_ignored(
        "TCP_SERVER_IMPL",
        "Rust has a single async TCP server implementation — value accepted and ignored",
    );
    warn_if_set("TCP_REACTOR_NUM");
    warn_if_set("TCP_SERVER_MODE");

    let postgres_port = env_u16("TCP_POSTGRES_PORT", 5432);
    let mysql_port = env_u16("TCP_MYSQL_PORT", 3306);
    let mut ports: Vec<u16> = [postgres_port, mysql_port]
        .into_iter()
        .filter(|port| *port > 0)
        .collect();
    if ports.is_empty() {
        ports = vec![5432, 3306];
    }

    let workers = env_usize("TCP_WORKERS", cpus());
    let skip_validation = env_bool("TCP_SKIP_VALIDATION", false);
    let sockmap_enabled = env_bool("TCP_SOCKMAP_ENABLED", false);
    let sockmap_path = env::var("TCP_SOCKMAP_BPF_OBJECT").unwrap_or_default();
    let timeout = Duration::from_secs_f64(env_f64("TCP_TIMEOUT", 30.0).max(0.0));
    let connect_timeout = Duration::from_secs_f64(env_f64("TCP_CONNECT_TIMEOUT", 5.0).max(0.0));

    let tls = build_tls()?;

    let mut config = TcpConfig::new(ports.clone())
        .with_workers(workers)
        .with_skip_validation(skip_validation)
        .with_timeout(timeout)
        .with_connect_timeout(connect_timeout);
    if sockmap_enabled && !sockmap_path.is_empty() {
        config = config.with_sockmap(PathBuf::from(&sockmap_path));
    }
    if let Some(tls) = tls.clone() {
        config = config.with_tls(tls);
    }

    let mut details: Vec<(&str, String)> = vec![
        (
            "Ports",
            ports
                .iter()
                .map(u16::to_string)
                .collect::<Vec<_>>()
                .join(", "),
        ),
        ("Workers", workers.to_string()),
        ("Timeout", format!("{:.2?}", timeout)),
        ("Connect", format!("{:.2?}", connect_timeout)),
    ];
    if tls.is_some() {
        details.push(("TLS", "enabled".to_string()));
    }
    if sockmap_enabled {
        details.push((
            "Sockmap",
            if sockmap_path.is_empty() {
                "enabled (no object)".to_string()
            } else {
                format!("enabled ({sockmap_path})")
            },
        ));
    }

    banner(Protocol::Tcp, resolver_description, &details);

    let server = TcpServer::new(resolver, config);
    tokio::select! {
        result = server.start() => result.map_err(|e| format!("TCP server exited: {e}")),
        _ = shutdown_signal() => {
            info!("shutdown signal received");
            Ok(())
        }
    }
}

async fn run_http(resolver: Arc<dyn Resolver>, resolver_description: &str) -> Result<(), String> {
    log_accepted_but_ignored(
        "HTTP_SERVER_IMPL",
        "Rust has a single async HTTP server implementation — value accepted and ignored",
    );
    warn_if_set("HTTP_REACTOR_NUM");
    warn_if_set("HTTP_SERVER_MODE");

    let port = env_u16("HTTP_PORT", 8080);
    let workers = env_usize("HTTP_WORKERS", cpus() * 2);
    let pool_size = env_usize("HTTP_BACKEND_POOL_SIZE", 2048);
    let keepalive_timeout = env_u64("HTTP_KEEPALIVE_TIMEOUT", 60);
    let http2_enabled = env_bool("HTTP_OPEN_HTTP2", false);
    let skip_validation = env_bool("HTTP_SKIP_VALIDATION", false);
    let fast_path = env_bool("HTTP_FAST_PATH", true);
    let raw_backend = env_bool("HTTP_RAW_BACKEND", false);

    let mut config = HttpConfig::new()
        .with_port(port)
        .with_workers(workers)
        .with_pool_size(pool_size)
        .with_http2(http2_enabled)
        .with_skip_validation(skip_validation)
        .with_raw_backend(raw_backend);
    config.keepalive_timeout = keepalive_timeout;
    config.fast_path = fast_path;

    let details: Vec<(&str, String)> = vec![
        ("Port", port.to_string()),
        ("Workers", workers.to_string()),
        ("Pool", pool_size.to_string()),
        ("Keepalive", format!("{keepalive_timeout}s")),
        ("HTTP/2", http2_enabled.to_string()),
        ("FastPath", fast_path.to_string()),
        ("RawBackend", raw_backend.to_string()),
    ];
    banner(Protocol::Http, resolver_description, &details);

    let server = HttpServer::new(Some(resolver), config);
    tokio::select! {
        result = server.start() => result.map_err(|e| format!("HTTP server exited: {e}")),
        _ = shutdown_signal() => {
            info!("shutdown signal received");
            Ok(())
        }
    }
}

async fn run_smtp(resolver: Arc<dyn Resolver>, resolver_description: &str) -> Result<(), String> {
    let port = env_u16("SMTP_PORT", 25);
    let workers = env_usize("SMTP_WORKERS", cpus() * 2);
    let skip_validation = env_bool("SMTP_SKIP_VALIDATION", false);

    let config = SmtpConfig {
        port,
        workers,
        skip_validation,
        ..SmtpConfig::default()
    };

    let details: Vec<(&str, String)> = vec![
        ("Port", port.to_string()),
        ("Workers", workers.to_string()),
        ("SkipValid", skip_validation.to_string()),
    ];
    banner(Protocol::Smtp, resolver_description, &details);

    let server = SmtpServer::new(resolver, config);
    tokio::select! {
        result = server.start() => result.map_err(|e| format!("SMTP server exited: {e}")),
        _ = shutdown_signal() => {
            info!("shutdown signal received");
            Ok(())
        }
    }
}

async fn shutdown_signal() {
    let _ = signal::ctrl_c().await;
}

#[tokio::main]
async fn main() -> ExitCode {
    init_logging();

    let cli = Cli::parse();
    let protocol = match resolve_protocol(cli.command.as_ref()) {
        Ok(protocol) => protocol,
        Err(message) => {
            eprintln!("{message}");
            return ExitCode::from(1);
        }
    };

    let (resolver, resolver_description) = build_resolver(protocol);

    let result = match protocol {
        Protocol::Tcp => run_tcp(resolver, &resolver_description).await,
        Protocol::Http => run_http(resolver, &resolver_description).await,
        Protocol::Smtp => run_smtp(resolver, &resolver_description).await,
    };

    match result {
        Ok(()) => ExitCode::SUCCESS,
        Err(message) => {
            eprintln!("ERROR: {message}");
            ExitCode::from(1)
        }
    }
}
