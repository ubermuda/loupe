# Builds the demo image together with the production image it is layered on.
#
# The demo image's `FROM` is satisfied by the `prod` target's output through a
# named context rather than a registry pull, which is why this file exists at
# all: `docker buildx build` can only name contexts that already exist, while
# bake can name another target. Two things follow, both of them the point.
#
# A multi-platform demo image needs no multi-platform production image
# published alongside it — the base is built here, for each platform, and never
# leaves the build. And the `prod` target carries no tag, so building the demo
# image cannot overwrite a deployable `ghcr.io/ubermuda/loupe:prod` with a
# host-architecture build.
#
# Driven by `just build-demo` and `just push-demo`; see those for the arguments.

variable "PROD_IMAGE" { default = "ghcr.io/ubermuda/loupe:prod" }
variable "DEMO_IMAGE" { default = "ghcr.io/ubermuda/loupe:demo" }
variable "APP_VERSION" { default = "" }
variable "APP_SOURCE_URL" { default = "https://github.com/ubermuda/loupe" }
variable "PLATFORMS" { default = "linux/amd64,linux/arm64" }

target "prod" {
  context    = "."
  dockerfile = "docker/prod/Dockerfile"
  args       = { APP_VERSION = APP_VERSION, APP_SOURCE_URL = APP_SOURCE_URL }
  platforms  = split(",", PLATFORMS)
}

target "demo" {
  context    = "."
  dockerfile = "docker/demo/Dockerfile"
  platforms  = split(",", PLATFORMS)
  tags       = [DEMO_IMAGE]
  # Keyed by the reference the demo Dockerfile's FROM resolves to, so that
  # reference is served by the target above instead of being pulled.
  contexts = { "${PROD_IMAGE}" = "target:prod" }
}
