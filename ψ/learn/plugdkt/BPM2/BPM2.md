# BPM2 Learning Index

## Source
- **This repo** (learned in place — not cloned, it's the project this Oracle lives in)
- **GitHub**: https://github.com/plugdkt/BPM2

## Explorations

### 2026-08-27 0949 (fast)
- [2026-08-27/0949_OVERVIEW](2026-08-27/0949_OVERVIEW.md)

**Key insights**:
- Budget tracking system for MEDSCI (คณะวิทยาศาสตร์การแพทย์ ม.พะเยา) — real-time allocation/spend/transfer tracking, PHP 8.2 + MariaDB on IIS, SSO-only login via MEDSCI ACC (no local passwords)
- Core patterns worth remembering: centralized balance calc (`bpm_line_item_balance()` as single source of truth), PRG pattern on every form, PDO prepared statements throughout, Thai fiscal year (Oct 1–Sep 30, BE)
- Export pipeline (Excel/PDF) needed a non-obvious Dompdf `chroot`/`isRemoteEnabled` fix to get Thai font embedding working — documented in spec.md's Tech Stack table
