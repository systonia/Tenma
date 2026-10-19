//! --tls-cert and --tls-key: HTTPS on the ports of --tls-port, or on every port the script serves
//! without it. The workers do the handshakes, each on its own thread. SIGHUP reads the files again,
//! so a renewed certificate needs no restart.

use std::sync::{Arc, OnceLock, PoisonError, RwLock};

use rustls::crypto::CryptoProvider;
use rustls::pki_types::pem::PemObject;
use rustls::pki_types::{CertificateDer, PrivateKeyDer};
use rustls::server::{ClientHello, ResolvesServerCert};
use rustls::sign::CertifiedKey;
use tokio_rustls::TlsAcceptor;

/// The paths as given, for SIGHUP.
struct Files {
    certificate: String,
    key: String,
}

static FILES: OnceLock<Files> = OnceLock::new();
static CERTIFICATE: OnceLock<Arc<Current>> = OnceLock::new();
static ACCEPTOR: OnceLock<TlsAcceptor> = OnceLock::new();
/// The ports of --tls-port; empty: every port.
static PORTS: OnceLock<Vec<u16>> = OnceLock::new();

/// The certificate every handshake gets; SIGHUP swaps it. A running connection keeps the one it
/// started with.
#[derive(Debug)]
struct Current(RwLock<Arc<CertifiedKey>>);

impl ResolvesServerCert for Current {
    fn resolve(&self, _: ClientHello<'_>) -> Option<Arc<CertifiedKey>> {
        let current = self.0.read().unwrap_or_else(PoisonError::into_inner);
        Some(Arc::clone(&current))
    }
}

fn provider() -> CryptoProvider {
    rustls::crypto::ring::default_provider()
}

/// The certificate chain and the key (PEM), checked to belong together.
fn load(certificate: &str, key: &str) -> Result<CertifiedKey, String> {
    let chain = CertificateDer::pem_file_iter(certificate)
        .and_then(|certificates| certificates.collect::<Result<Vec<_>, _>>())
        .map_err(|error| format!("--tls-cert {certificate}: {error}"))?;
    if chain.is_empty() {
        return Err(format!(
            "--tls-cert {certificate}: no certificate in the file"
        ));
    }
    let private =
        PrivateKeyDer::from_pem_file(key).map_err(|error| format!("--tls-key {key}: {error}"))?;

    CertifiedKey::from_der(chain, private, &provider())
        .map_err(|error| format!("--tls-cert and --tls-key do not fit together: {error}"))
}

/// Reads the files for every later connection. Only before the workers.
pub fn configure(certificate: String, key: String, ports: Vec<u16>) -> Result<(), String> {
    let current = Arc::new(Current(RwLock::new(Arc::new(load(&certificate, &key)?))));

    let mut config = rustls::ServerConfig::builder_with_provider(Arc::new(provider()))
        .with_safe_default_protocol_versions()
        .map_err(|error| error.to_string())?
        .with_no_client_auth()
        .with_cert_resolver(current.clone());
    config.alpn_protocols = vec![b"http/1.1".to_vec()];

    let _ = FILES.set(Files { certificate, key });
    let _ = CERTIFICATE.set(current);
    let _ = PORTS.set(ports);
    let _ = ACCEPTOR.set(TlsAcceptor::from(Arc::new(config)));
    Ok(())
}

/// Whether --tls-cert is set.
pub fn enabled() -> bool {
    ACCEPTOR.get().is_some()
}

/// The ports of --tls-port; empty: every port.
pub fn ports() -> &'static [u16] {
    PORTS.get().map_or(&[], Vec::as_slice)
}

/// The handshake for a new connection on `port`, or None if that port speaks plain HTTP.
pub fn acceptor(port: u16) -> Option<&'static TlsAcceptor> {
    let acceptor = ACCEPTOR.get()?;
    let ports = ports();
    (ports.is_empty() || ports.contains(&port)).then_some(acceptor)
}

/// SIGHUP: reads the files again. If they are broken, the old certificate stays.
pub fn reload() {
    let (Some(files), Some(current)) = (FILES.get(), CERTIFICATE.get()) else {
        return;
    };
    match load(&files.certificate, &files.key) {
        Ok(loaded) => {
            *current.0.write().unwrap_or_else(PoisonError::into_inner) = Arc::new(loaded);
            eprintln!("tenma: certificate reloaded");
        }
        Err(message) => eprintln!("tenma: {message}, keeping the previous certificate"),
    }
}
