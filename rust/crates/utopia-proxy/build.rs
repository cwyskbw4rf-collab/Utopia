fn main() {
    println!("cargo:rerun-if-changed=build.rs");
    println!("cargo:rerun-if-env-changed=UTOPIA_PROXY_BPF_OBJECT");
}
