# Changelog

## 0.3.0
- First public release.
- Code files of a build (HTML, JS, CSS, JSON, SVG, WASM ...) are stored in a database table (`{prefix}stasimo_files`) and never written to disk. Only media files go to `stasimo-static-site-mount/<mount>/<build>/` in the uploads directory, resolved with `wp_upload_dir()`.
- Removed the per-mount folder option and the `stasimo_base_dir` and `stasimo_allow_external_dirs` filters.
- Removing a mount deletes its build; deleting the plugin removes its table, options and media folder.
