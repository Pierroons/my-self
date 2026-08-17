#!/bin/bash
# MySelf-Live — orchestrator script (skeleton, TODOs only)
#
# This is the master build entrypoint. It calls the staged scripts in scripts/
# in order, and produces a signed ISO in dist/.
#
# Usage : ./build.sh [--clean] [--no-sign]
#
# Status : SKELETON — no working logic yet. Each TODO marker indicates a
# stage to implement. Run order is intentionally documented even though
# scripts are placeholders.

set -euo pipefail

VERSION="0.2.0-alpha-pre"
WORKDIR="$(cd "$(dirname "$0")" && pwd)"
DIST="${WORKDIR}/dist"
SCRIPTS="${WORKDIR}/scripts"
CONFIG="${WORKDIR}/config"

mkdir -p "${DIST}"

log() { echo "[myself-live $(date +%H:%M:%S)] $*"; }

# ---------------------------------------------------------------------------
# Pre-flight
# ---------------------------------------------------------------------------
log "==== MySelf-Live build ${VERSION} ===="

# TODO-M1 : detect host distribution and verify required tools are installable
#   - debootstrap, live-build, xorriso, mksquashfs, GPG, sigstore cosign
#   - target host : Debian 12+ or Ubuntu 24.04+

# TODO-M1 : check disk space (need ~6 GB working room)
# TODO-M1 : check that /tmp is large enough or use --tmpdir override

# ---------------------------------------------------------------------------
# Stage 0 : prepare host environment
# ---------------------------------------------------------------------------
log "Stage 0 — prepare host"
# TODO-M1 : "${SCRIPTS}/00-prepare-host.sh"
#   Installs build deps (apt install live-build debootstrap ...)
#   Verifies versions match those pinned in config/package-list-base.txt

# ---------------------------------------------------------------------------
# Stage 1 : debootstrap minimal Debian rootfs
# ---------------------------------------------------------------------------
log "Stage 1 — debootstrap"
# TODO-M1 : "${SCRIPTS}/10-debootstrap.sh"
#   debootstrap --variant=minbase --components=main \
#     bookworm "${WORKDIR}/chroot" \
#     https://snapshot.debian.org/archive/debian/<DATE>/
#   Pinned snapshot date for reproducibility.

# ---------------------------------------------------------------------------
# Stage 2 : install packages from curated list
# ---------------------------------------------------------------------------
log "Stage 2 — install packages"
# TODO-M1 : "${SCRIPTS}/20-install-packages.sh"
#   chroot "${WORKDIR}/chroot" apt-get install -y --no-install-recommends \
#     $(cat "${CONFIG}/package-list-base.txt")
#   Verify package hashes against pinned manifest.

# TODO-M2 : "${SCRIPTS}/20-install-packages.sh" (extra stage)
#   Installs Tor, Firefox ESR (hardened profile), gnupg, sequoia-pgp,
#   pinentry-tty, gpgv, dice rolling visualizer (custom binary or shell tool)

# ---------------------------------------------------------------------------
# Stage 3 : embed SelfRecover artifacts
# ---------------------------------------------------------------------------
log "Stage 3 — embed SelfRecover"
# TODO-M2 : "${SCRIPTS}/30-embed-selfrecover.sh"
#   Copies into chroot/opt/selfrecover/ :
#     - bi-self/selfrecover/ (current repo state)
#     - demo/docs/diceware-method-{en,fr}.pdf (preinstalled in /home/myself/Documents/)
#     - selfrecover/tools/offline-validator/index.html (in /home/myself/tools/)
#   Sets a desktop launcher for SelfRecover daemon.

# ---------------------------------------------------------------------------
# Stage 4 : kernel hardening
# ---------------------------------------------------------------------------
log "Stage 4 — harden kernel"
# TODO-M3 : "${SCRIPTS}/40-harden-kernel.sh"
#   Apply :
#     - CONFIG_LOCKDOWN_LSM=y, lockdown=integrity in cmdline
#     - blacklist : usb-storage, bluetooth, all WiFi modules
#     - sysctl : kernel.kexec_load_disabled=1, kernel.unprivileged_bpf_disabled=1
#     - /etc/modprobe.d/myself-blacklist.conf

# ---------------------------------------------------------------------------
# Stage 5 : disable network by default
# ---------------------------------------------------------------------------
log "Stage 5 — disable network"
# TODO-M3 : "${SCRIPTS}/50-disable-network.sh"
#   Install systemd unit disable-net.service that :
#     - Runs before network-online.target
#     - iptables -P INPUT DROP, FORWARD DROP, OUTPUT DROP
#     - Disables NetworkManager and systemd-networkd
#   User can opt-in via "Enable Tor" button in tray.

# ---------------------------------------------------------------------------
# Stage 6 : build squashfs + ISO
# ---------------------------------------------------------------------------
log "Stage 6 — build ISO"
# TODO-M1 : "${SCRIPTS}/60-build-iso.sh"
#   mksquashfs "${WORKDIR}/chroot" "${WORKDIR}/filesystem.squashfs" -comp xz
#   xorriso -as mkisofs -iso-level 3 -full-iso9660-filenames \
#           -volid "MYSELF_LIVE_${VERSION}" \
#           -appid "MySelf-Live ${VERSION}" \
#           -eltorito-boot isolinux/isolinux.bin \
#           -eltorito-catalog isolinux/boot.cat \
#           -no-emul-boot -boot-load-size 4 -boot-info-table \
#           -isohybrid-mbr /usr/lib/ISOLINUX/isohdpfx.bin \
#           -output "${DIST}/myself-live-${VERSION}.iso" \
#           "${WORKDIR}/iso/"

# ---------------------------------------------------------------------------
# Stage 7 : sign ISO (GPG offline + cosign)
# ---------------------------------------------------------------------------
log "Stage 7 — sign ISO"
if [[ "${1:-}" != "--no-sign" ]]; then
    # TODO-M5 : "${SCRIPTS}/70-sign-iso.sh"
    #   gpg --detach-sign --armor "${DIST}/myself-live-${VERSION}.iso"
    #   cosign sign-blob --key signing/myself-cosign.key \
    #     --output-signature "${DIST}/myself-live-${VERSION}.iso.sig" \
    #     "${DIST}/myself-live-${VERSION}.iso"
    #   Compute SHA256 + SHA512, write to manifest.txt + sign manifest
    log "  (signing disabled in skeleton)"
else
    log "  signing skipped (--no-sign)"
fi

# ---------------------------------------------------------------------------
# Stage 8 : verify reproducibility
# ---------------------------------------------------------------------------
log "Stage 8 — verify reproducibility"
# TODO-M4 : "${SCRIPTS}/80-verify-iso.sh"
#   reprotest -c "./build.sh --no-sign" \
#     -v "filesystem.squashfs" \
#     -t time +variation
#   Expected : two builds = identical hash

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
log "Build complete (skeleton — no actual artifacts produced)"
log "ISO would be at : ${DIST}/myself-live-${VERSION}.iso"
log ""
log "To implement next : Stage 1 (10-debootstrap.sh) — see scripts/"
