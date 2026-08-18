# Removed sensitive and runtime files

This commit removes sensitive and runtime-specific files from the repository HEAD and adds them to .gitignore.

Files removed:
- dr.armankabir-main.zip
- public_html/phpmyadmin/ (directory)
- access-logs (symlink)
- error_log (top-level)
- public_html/error_log
- server-data/ (tracked files removed)
- logs/ and tmp/
- .lastlogin
- .myimunify_id
- .imunify_patch_id

Security: After removing these files from HEAD, secrets may still exist in git history. To fully purge them you must run a history cleanup (BFG or git filter-repo) and force-push. Also rotate any exposed credentials immediately.
