<?php

namespace BshsAms\Security;

final class HttpAccessPolicy
{
    public static function allows(string $path): bool
    {
        // Reject ambiguous paths before filesystem resolution (including Windows aliases).
        if (preg_match('/[\\\\\x00-\x20:%]/', $path) || preg_match('#(?:^|/)\.|[. ](?:/|$)|//#', $path)) {
            return false;
        }
        if (in_array($path, ['/', '/index.php', '/sw.js'], true)) {
            return true;
        }
        if (preg_match('#^/(?:auth|principal|admin|teacher|student|parent)/[A-Za-z0-9_-]+\.php$#', $path)) {
            return !preg_match('/_(?:helper|functions)\.php$/i', $path);
        }
        if (in_array($path, ['/site/', '/site/index.php', '/api/index.php'], true)) {
            return true;
        }
        if (preg_match('#^/assets/uploads/#i', $path)) {
            // Only public profile/site images are served directly; documents use authorized handlers.
            return !preg_match('#^/assets/uploads/(?:materials|ecr)(?:/|$)#i', $path)
                && (bool)preg_match('/\.(?:png|jpe?g|gif|webp|ico)$/i', $path);
        }
        return (bool)preg_match('#^/assets/[A-Za-z0-9_./@+-]+\.(?:css|js|json|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|otf)$#i', $path);
    }
}
