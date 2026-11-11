# The image each agent run starts in when FACTORY_RUNNER_DRIVER=docker.
#
#   docker build -t software-factory-runner:latest docker/runner
#
# It holds Claude Code and the tools Spec Kit's scripts use (bash, git, jq, ripgrep).
# The app runs git and `specify init` itself; this image is only for the agent.
#
# Projects that need their own toolchain to run tests during Implement (PHP, Composer,
# Go, ...) can build on top of it:
#
#   FROM software-factory-runner:latest
#   USER root
#   RUN apt-get update && apt-get install -y --no-install-recommends php-cli composer
#
# and point FACTORY_DOCKER_IMAGE at the new image.

FROM node:22-bookworm-slim

# The Claude Code version this app was tested with. Upgrade on purpose, not by accident.
ARG CLAUDE_CODE_VERSION=2.1.318

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash ca-certificates curl git jq less procps python3 python3-venv ripgrep unzip \
    && rm -rf /var/lib/apt/lists/*

RUN npm install --global "@anthropic-ai/claude-code@${CLAUDE_CODE_VERSION}" \
    && npm cache clean --force

# Runs are started with --user <app user>, so HOME points at the container's private /tmp.
ENV HOME=/tmp \
    DISABLE_AUTOUPDATER=1 \
    CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1

RUN git config --system --add safe.directory '*'

CMD ["claude", "--version"]
