# Selector discovery and promotion

The production registry is `config/selectors.json` and starts with both platforms disabled. Do not set `configured` to `true` until an authenticated, read-only discovery run has been reviewed.

1. Create cached sessions with the Laravel session commands.
2. Run `soundon:selectors:discover` for a single Soundfresh detail page and the SoundOn create-release page.
3. Review the sanitized control inventory under Laravel private storage.
4. Copy only stable selectors into `config/selectors.json`. Prefer `testId`, then label/role; CSS is accepted only when it starts with an element ID or `data-*` attribute.
5. Keep `configured: false`, build the worker, and review the registry diff.
6. Enable Soundfresh first and run `soundon:process-pending --limit=1 --dry-run`.
7. Enable SoundOn only for a controlled one-release draft-only UAT.

The worker refuses arbitrary action names. The only accepted SoundOn mutation is `save_draft`. A registry whose configured save control contains Submit, Publish, Distribute, Send for review, or Release now is rejected by the runtime guard.
