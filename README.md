
## Deploying to Render

This repository already includes a `render.yaml` that targets the Rust Dockerfile (`rust/Dockerfile`). I added a small startup wrapper so the container can run as a Render Web service out of the box.

Quick steps to deploy on Render:

1. Sign in to Render and create a new "Web Service".
2. Connect your GitHub repository `cwyskbw4rf-collab/Utopia` and pick the `main` branch.
3. Render will detect `render.yaml` and create a service named `utopia-proxy` using `rust/Dockerfile`.
4. The container defaults to HTTP mode; Render will provide a `$PORT` environment variable and the startup wrapper maps it to `HTTP_PORT` for the Rust binary.

Environment variables you may want to set in Render (Render UI -> Advanced -> Environment):

- `PROXY_PROTOCOL` (optional) — defaults to `http` in the container start script.
- `HTTP_BACKEND_ENDPOINT` — backend host:port that the proxy will route to (example: `host.docker.internal:5678` for local dev via Docker Desktop). Default in repo: `127.0.0.1:5678`.
- `TCP_BACKEND_ENDPOINT` / `SMTP_BACKEND_ENDPOINT` — only needed if you run TCP/SMTP mode.

Notes & caveats

- The Rust build in `rust/Dockerfile` is a multi-stage build that compiles the binary inside the image. Building on Render may hit build-time or memory limits — if you see build failures, prefer pre-building the image in CI and pushing to a Container Registry (Github Container Registry, Docker Hub), then configure Render to use that image (Private Image).

- Host-level features (BPF/sockmap, host networking, advanced capabilities) are not available in Render's managed container environment. For production TCP/sockmap workloads you will need to run on infrastructure that allows the required capabilities.

- If you want me to add a GitHub Actions workflow that builds the Docker image and pushes it to GHCR or Docker Hub, I can add it below.
