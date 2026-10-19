//! --tls-cert and --tls-key: every port the script serves speaks HTTPS with that certificate. The
//! workers do the handshake, each on its own thread.

use std::sync::{Arc, OnceLock};

use rustls::pki_types::pem::PemObject;
use rustls::pki_types::{CertificateDer, PrivateKeyDer};
use tokio_rustls::TlsAcceptor;

// ponytail: the certificate is read once at the start; a renewed one needs a restart, reload it on
// SIGHUP once certificates renew without one
static ACCEPTOR: OnceLock<TlsAcceptor> = OnceLock::new();

/// Reads the certificate chain and the key (PEM) for every later connection. Only before the workers.
pub fn configure(certificate: &str, key: &str) -> Result<(), String> {
    let chain = CertificateDer::pem_file_iter(certificate)
        .and_then(|certificates| certificates.collect::<Result<Vec<_>, _>>())
        .map_err(|error| format!("--tls-cert {certificate}: {error}"))?;
    if chain.is_empty() {
        return Err(format!(
            "--tls-cert {certificate}: no certificate in the file"
        ));
    }
    let key =
        PrivateKeyDer::from_pem_file(key).map_err(|error| format!("--tls-key {key}: {error}"))?;

    let mut config = rustls::ServerConfig::builder_with_provider(Arc::new(
        rustls::crypto::ring::default_provider(),
    ))
    .with_safe_default_protocol_versions()
    .map_err(|error| error.to_string())?
    .with_no_client_auth()
    .with_single_cert(chain, key)
    .map_err(|error| format!("--tls-cert and --tls-key do not fit together: {error}"))?;
    config.alpn_protocols = vec![b"http/1.1".to_vec()];

    let _ = ACCEPTOR.set(TlsAcceptor::from(Arc::new(config)));
    Ok(())
}

/// The handshake for new connections, or None without --tls-cert.
pub fn acceptor() -> Option<&'static TlsAcceptor> {
    ACCEPTOR.get()
}
