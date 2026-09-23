<?php

namespace Sauron\Zed;

/**
 * Where Zed keeps its settings and extensions for this machine.
 *
 * Zed either runs here, or runs on Windows and opens this machine as a WSL
 * remote. In the second case a zed-remote-server spawns the language servers
 * here, while the editor keeps its own settings and extensions on Windows.
 */
final class Host
{
    public static function dataDir(): string
    {
        return $_SERVER['HOME'] . '/.local/share/zed';
    }

    /**
     * One directory per installed extension: the editor's own, and the copy a
     * remote server receives from the editor.
     *
     * @return list<string>
     */
    public static function extensionDirs(): array
    {
        return [self::dataDir() . '/extensions/installed', self::dataDir() . '/remote_extensions'];
    }

    /**
     * Where extensions download their servers, one directory per extension.
     *
     * @return list<string>
     */
    public static function workDirs(): array
    {
        return [self::dataDir() . '/extensions/work', self::dataDir() . '/remote_extensions/work'];
    }

    public static function hasExtension(string $id): bool
    {
        foreach (self::extensionDirs() as $dir) {
            if (is_dir($dir . '/' . $id)) {
                return true;
            }
        }

        return false;
    }

    /** The settings.json of a Zed installed on Windows, when this is WSL. */
    public static function windowsSettingsPath(): ?string
    {
        $appData = self::windowsDir('APPDATA');

        if (null === $appData || !is_file($appData . '/Zed/settings.json')) {
            return null;
        }

        return $appData . '/Zed/settings.json';
    }

    /**
     * Where Sauron keeps what the editor itself has to read, like the sources
     * of a dev extension it builds.
     *
     * Zed on Windows cannot build from a \\wsl.localhost path: cargo fails to
     * lock its incremental compilation directory on that filesystem. So when
     * the editor is on Windows, those files live on the Windows side.
     */
    public static function editorDataDir(): string
    {
        $localAppData = self::isWindowsEditor() ? self::windowsDir('LOCALAPPDATA') : null;

        return null !== $localAppData
            ? $localAppData . '/sauron-lsp'
            : $_SERVER['HOME'] . '/.local/share/sauron-lsp';
    }

    public static function isWindowsEditor(): bool
    {
        return null !== self::windowsSettingsPath();
    }

    /** A path of this machine, as the editor's file picker has to be given it. */
    public static function editorPath(string $path): string
    {
        if (!self::isWindowsEditor()) {
            return $path;
        }

        $windows = trim((string) shell_exec(\sprintf('wslpath -w %s 2>/dev/null', escapeshellarg($path))));

        return '' === $windows ? $path : $windows;
    }

    /** A Windows folder named by an environment variable, as WSL sees it. */
    private static function windowsDir(string $variable): ?string
    {
        static $dirs = [];

        if (\array_key_exists($variable, $dirs)) {
            return $dirs[$variable];
        }

        if (false === getenv('WSL_DISTRO_NAME')) {
            return $dirs[$variable] = null;
        }

        $windows = trim((string) shell_exec(\sprintf('cmd.exe /c "echo %%%s%%" 2>/dev/null', $variable)));

        if ('' === $windows || str_contains($windows, '%')) {
            return $dirs[$variable] = null;
        }

        $path = trim((string) shell_exec(\sprintf('wslpath -u %s 2>/dev/null', escapeshellarg($windows))));

        return $dirs[$variable] = '' === $path ? null : $path;
    }
}
