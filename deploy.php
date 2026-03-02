<?php

namespace Deployer;

require 'recipe/laravel.php';

// Config

set('repository', 'git@github.com:pochaneco/tools.pochaneco.space.git');

// Build command for frontend assets via Sail (adjust if you use yarn/pnpm/bun)
// Using vendor/bin/sail to avoid relying on shell aliases
set('build_cmd', 'vendor/bin/sail npm ci && vendor/bin/sail npm run build');

// Optional: Directories to check for local dirty state (array or space-separated string). Empty = whole repo
set('dirty_paths', ['resources/js', 'resources/css']);
// Optional: If true, abort deploy when dirty changes are detected; default warns only
set('dirty_fail', false);

// Optional: Expected local branch name; warn (or fail) if current is different
set('branch_expected', 'main');
// Optional: If true, abort deploy when branch mismatch is detected; default warns only
set('branch_fail', false);

// Optional: Local env file to upload when running env:push
set('env_local_file', '.env.production');

// Tasks

// Build assets locally so the server doesn't need Node.js
desc('Build assets locally');
task('assets:build', function (): void {
    runLocally('{{build_cmd}}');
});

// Upload local built assets to the server's release path
desc('Upload built assets');
task('assets:upload', function (): void {
    // Clean target directory to avoid stale files
    run('rm -rf {{release_path}}/public/build');
    // Upload the local build directory
    upload('public/build/', '{{release_path}}/public/build');
});

// Upload local .env file to shared/.env on the server (standalone)
desc('Upload local env file to remote shared/.env');
task('env:push', function (): void {
    $local = (string) get('env_local_file');

    // Ensure local file exists
    if (!file_exists($local)) {
        writeln(sprintf('<error>Local env file "%s" not found.</error>', $local));
        throw new \RuntimeException('Env file missing');
    }

    // Ensure shared directory exists, then upload and secure permissions
    run('mkdir -p {{deploy_path}}/shared');
    upload($local, '{{deploy_path}}/shared/.env');
    run('chmod 600 {{deploy_path}}/shared/.env');
    writeln('<info>Uploaded env file to {{deploy_path}}/shared/.env</info>');
});

// Warn (or fail) if current branch is not the expected one
desc('Warn if local git branch is not expected');
task('local:warn-branch', function (): void {
    $expected = (string) get('branch_expected');
    $branch = trim((string) runLocally('git rev-parse --abbrev-ref HEAD'));

    // Detached HEAD returns 'HEAD'
    if ($branch === 'HEAD') {
        writeln('<comment>WARNING:</comment> You are on a detached HEAD.');
        if (get('branch_fail')) {
            throw new \RuntimeException('Detached HEAD not allowed (branch_fail=true).');
        }
        return;
    }

    if ($branch !== $expected) {
        writeln(sprintf('<comment>WARNING:</comment> Current branch "%s" != expected "%s".', $branch, $expected));
        if (get('branch_fail')) {
            throw new \RuntimeException('Branch mismatch (branch_fail=true).');
        }
    }
});

// Warn (or fail) if there are uncommitted local changes before building
desc('Warn if local git working tree is dirty');
task('local:warn-dirty', function (): void {
    $paths = get('dirty_paths');
    if (is_string($paths)) {
        $paths = preg_split('/\s+/', trim($paths));
    }
    $paths = array_values(array_filter(is_array($paths) ? $paths : []));

    $pathsArg = '';
    if (!empty($paths)) {
        $quoted = array_map(static fn($p) => escapeshellarg($p), $paths);
        $pathsArg = ' -- ' . implode(' ', $quoted);
    }

    // --porcelain gives a stable, parseable output; non-empty => there are changes (including untracked)
    $output = (string) runLocally("git status --porcelain{$pathsArg}");
    if (trim($output) !== '') {
        $scoped = empty($paths) ? 'repository' : ('paths: ' . implode(', ', $paths));
        writeln("<comment>WARNING:</comment> Uncommitted local changes detected in {$scoped}.");
        // Show a short preview of changes
        $lines = explode("\n", trim($output));
        $preview = implode("\n", array_slice($lines, 0, 20));
        writeln("<comment>Changes:</comment>\n{$preview}" . (count($lines) > 20 ? "\n..." : ''));

        if (get('dirty_fail')) {
            writeln('<error>Aborting deploy due to dirty working tree (dirty_fail=true).</error>');
            throw new \RuntimeException('Local working tree is dirty');
        }
    }
});

// Hosts

host(getenv('DEPLOY_HOST') ?: 'example.sakura.ne.jp')
    ->set('remote_user', getenv('DEPLOY_USER') ?: 'deployer')
    ->set('deploy_path', getenv('DEPLOY_PATH') ?: '~/www/tools.pochaneco.space');

// Hooks
after('deploy:failed', 'deploy:unlock');
// Warn for local branch mismatch before building assets
before('assets:build', 'local:warn-branch');
// Warn for local dirty state before building assets
before('assets:build', 'local:warn-dirty');
// Run local build & upload just before switching the symlink
before('deploy:symlink', 'assets:build');
after('assets:build', 'assets:upload');
