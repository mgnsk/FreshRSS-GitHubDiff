# FreshRSS-GitHubDiff

FreshRSS extension that adds the full commit message and a colored diff to items of GitHub commit feeds (e.g. `https://github.com/NixOS/nixpkgs/commits/nixos-unstable.atom`).

- Runs once per new entry (`entry_before_insert`); existing entries are not backfilled.
- Configure a GitHub personal access token (public read access is enough) on the extension's settings page. It is stored in plaintext in the user's FreshRSS config.
- Diffs are capped at 30 files / ~200 KB per commit.
