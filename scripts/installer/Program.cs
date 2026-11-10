using System.Diagnostics;
using System.Reflection;
using System.Security.Cryptography;
using System.Text.RegularExpressions;
using System.Management;

namespace Dimensimatic.Installer;

internal static class Program
{
    [STAThread]
    private static void Main(string[] args)
    {
        var silentUpdate = args.Contains("--silent-update", StringComparer.OrdinalIgnoreCase);
        var detached = args.Contains("--detached", StringComparer.OrdinalIgnoreCase);
        if (silentUpdate && !detached)
        {
            // Older Dimensimatic versions launch the installer through Symfony
            // Process and dispose that process as soon as the HTTP request ends.
            // Relaunch immediately as an independent Windows shell process so
            // the updater cannot terminate the real installer with its parent.
            Process.Start(new ProcessStartInfo(Application.ExecutablePath)
            {
                Arguments = "--silent-update --detached",
                WorkingDirectory = AppContext.BaseDirectory,
                UseShellExecute = true,
            });
            return;
        }

        ApplicationConfiguration.Initialize();
        Application.Run(new InstallerWindow(silentUpdate));
    }

    private sealed class InstallerWindow : Form
    {
        private readonly Label status = new() { Dock = DockStyle.Top, Height = 70, TextAlign = ContentAlignment.MiddleCenter, Text = "Dimensimatic Setup v1.1.104", Font = new Font("Segoe UI", 14, FontStyle.Bold) };
        private readonly ProgressBar progress = new() { Dock = DockStyle.Top, Height = 24, Style = ProgressBarStyle.Marquee };
        private readonly Button install = new() { Dock = DockStyle.Top, Height = 46, Text = "Install Dimensimatic" };
        private readonly Label note = new() { Dock = DockStyle.Fill, Padding = new Padding(18), TextAlign = ContentAlignment.TopLeft, Text = "Aplikasi akan dipasang untuk pengguna Windows saat ini.\n\nLokasi: %LOCALAPPDATA%\\Programs\\Dimensimatic\n\nSetelah instalasi, login awal: admin / admin. Segera ganti password setelah masuk." };
        private readonly bool silentUpdate;
        private static readonly string InstallerLogPath = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "Dimensimatic",
            "installer.log");

        public InstallerWindow(bool silentUpdate = false)
        {
            this.silentUpdate = silentUpdate;
            Text = "Dimensimatic Setup"; Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath); Width = 540; Height = 330; StartPosition = FormStartPosition.CenterScreen; FormBorderStyle = FormBorderStyle.FixedDialog; MaximizeBox = false;
            Controls.Add(note); Controls.Add(install); Controls.Add(progress); Controls.Add(status); progress.Visible = false;
            install.Click += async (_, _) => await InstallAsync();
            if (silentUpdate)
            {
                install.Visible = false;
                note.Text = "Update Dimensimatic sedang dipasang otomatis. Data aplikasi dan credential tetap dipertahankan.";
                Shown += async (_, _) => await InstallAsync();
            }
        }

        private async Task InstallAsync()
        {
            install.Enabled = false; progress.Visible = true;
            var target = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Programs", "Dimensimatic");
            var temp = Path.Combine(Path.GetTempPath(), "DimensimaticSetup-" + Guid.NewGuid().ToString("N"));
            var isNewInstallation = !File.Exists(Path.Combine(target, ".env"));
            try
            {
                Log("Instalasi dimulai. Mode otomatis: " + silentUpdate);
                Directory.CreateDirectory(temp); Directory.CreateDirectory(target);
                status.Text = "Menghentikan Dimensimatic yang sedang berjalan...";
                Log(status.Text);
                await StopRunningApplication(target);
                DeleteOldWorkerFiles(target);
                status.Text = "Mengekstrak aplikasi...";
                Log(status.Text);
                var payload = Path.Combine(temp, "payload.7z"); var sevenZip = Path.Combine(temp, "7z.exe"); var sevenZipLibrary = Path.Combine(temp, "7z.dll");
                ExtractResource("Dimensimatic.payload.7z", payload); ExtractResource("Dimensimatic.7z.exe", sevenZip); ExtractResource("Dimensimatic.7z.dll", sevenZipLibrary);
                await Run(sevenZip, $"x -y -o\"{target}\" \"{payload}\"", temp);
                status.Text = "Menyiapkan database dan akun awal...";
                Log(status.Text);
                PrepareConfiguration(target, isNewInstallation);
                await EnsureHealthyDatabase(target);
                await Run(Path.Combine(target, "runtime", "php", "php.exe"), "artisan migrate --force --seed", target);
                await Run(Path.Combine(target, "runtime", "php", "php.exe"), "artisan dimensimatic:bootstrap", target);
                CreateShortcut(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory), "Dimensimatic.lnk"), Path.Combine(target, "Dimensimatic.exe"), target);
                CreateShortcut(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.StartMenu), "Programs", "Dimensimatic.lnk"), Path.Combine(target, "Dimensimatic.exe"), target);
                status.Text = silentUpdate ? "Update selesai." : "Instalasi selesai."; progress.Visible = false;
                Log(status.Text);
                if (silentUpdate || MessageBox.Show("Dimensimatic berhasil diinstal. Buka sekarang?", "Selesai", MessageBoxButtons.YesNo, MessageBoxIcon.Information) == DialogResult.Yes)
                    Process.Start(new ProcessStartInfo(Path.Combine(target, "Dimensimatic.exe")) { WorkingDirectory = target, UseShellExecute = true });
                ScheduleSelfDelete();
                Close();
            }
            catch (Exception error)
            {
                Log("Instalasi gagal: " + error);
                progress.Visible = false;
                install.Enabled = true;
                status.Text = "Instalasi gagal";
                MessageBox.Show(error.Message + "\n\nLog: " + InstallerLogPath, "Dimensimatic Setup", MessageBoxButtons.OK, MessageBoxIcon.Error);
            }
            finally { try { Directory.Delete(temp, true); } catch { } }
        }

        private static void Log(string message)
        {
            try
            {
                Directory.CreateDirectory(Path.GetDirectoryName(InstallerLogPath)!);
                File.AppendAllText(InstallerLogPath, $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss}] {message}{Environment.NewLine}");
            }
            catch { }
        }

        private static void ScheduleSelfDelete()
        {
            try
            {
                var executable = Application.ExecutablePath;
                var script = Path.Combine(Path.GetTempPath(), "Dimensimatic-cleanup-" + Guid.NewGuid().ToString("N") + ".cmd");
                File.WriteAllLines(script, new[]
                {
                    "@echo off",
                    "timeout /T 3 /NOBREAK >NUL",
                    $"del /F /Q \"{executable}\"",
                    @"del /F /Q ""%~f0""",
                });
                Process.Start(new ProcessStartInfo("cmd.exe")
                {
                    Arguments = $"/D /C \"\"{script}\"\"",
                    WorkingDirectory = Path.GetTempPath(),
                    UseShellExecute = false,
                    CreateNoWindow = true,
                });
            }
            catch { }
        }

        private static void ExtractResource(string name, string destination) { using var source = Assembly.GetExecutingAssembly().GetManifestResourceStream(name) ?? throw new InvalidOperationException($"Resource {name} tidak ditemukan."); using var output = File.Create(destination); source.CopyTo(output); }

        private static async Task StopRunningApplication(string target)
        {
            var normalizedTarget = Path.GetFullPath(target).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
            // Close the desktop host first. Its FormClosing handler normally stops
            // the PHP/Node children cleanly, preserving database and configuration.
            foreach (var process in Process.GetProcessesByName("Dimensimatic"))
            {
                try
                {
                    if (!BelongsToInstallation(process, normalizedTarget)) continue;
                    if (!process.CloseMainWindow()) process.Kill(entireProcessTree: true);
                    await WaitOrKill(process);
                }
                catch { TryKill(process); }
                finally { process.Dispose(); }
            }

            await StopOwnedRuntimeProcesses(target);

            var locked = new[] {
                Path.Combine(target, "runtime", "node", "node.exe"),
                Path.Combine(target, "runtime", "php", "php.exe"),
            }.Where(IsFileLocked).ToArray();
            if (locked.Length > 0)
                throw new InvalidOperationException("Dimensimatic masih menggunakan file runtime. Tutup aplikasi melalui Task Manager lalu jalankan installer kembali. File terkunci: " + string.Join(", ", locked.Select(Path.GetFileName)));
        }

        private static async Task StopOwnedRuntimeProcesses(string target)
        {
            var normalizedTarget = Path.GetFullPath(target).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
            var lastFound = new List<string>();
            for (var attempt = 0; attempt < 20; attempt++)
            {
                var owned = new List<(int Id, string Name, string Executable, string CommandLine)>();
                try
                {
                    using var query = new ManagementObjectSearcher("SELECT ProcessId, Name, ExecutablePath, CommandLine FROM Win32_Process WHERE Name='php.exe' OR Name='node.exe'");
                    foreach (ManagementObject item in query.Get())
                    {
                        var executable = (string?)item["ExecutablePath"] ?? string.Empty;
                        var command = ((string?)item["CommandLine"] ?? string.Empty).Replace('/', '\\');
                        var imageIsOwned = executable.StartsWith(normalizedTarget, StringComparison.OrdinalIgnoreCase);
                        var commandUsesInstall = command.Contains(normalizedTarget, StringComparison.OrdinalIgnoreCase);
                        if (!imageIsOwned && !commandUsesInstall) continue;
                        owned.Add((Convert.ToInt32(item["ProcessId"]), (string?)item["Name"] ?? "runtime.exe", executable, command));
                    }
                }
                catch (Exception error)
                {
                    Log($"Gagal membaca daftar proses runtime milik Dimensimatic (percobaan {attempt + 1}/20): {error.Message}");
                }

                if (owned.Count == 0)
                {
                    await Task.Delay(500); // let Windows release mapped executable files
                    var stillLocked = new[] {
                        Path.Combine(target, "runtime", "node", "node.exe"),
                        Path.Combine(target, "runtime", "php", "php.exe"),
                    }.Where(IsFileLocked).ToArray();
                    if (stillLocked.Length == 0) return;

                    lastFound = stillLocked.Select(path => Path.GetFileName(path) + " (file masih terkunci)").ToList();
                    Log("Menunggu Windows melepas file runtime: " + string.Join(", ", lastFound));
                    await Task.Delay(500);
                    continue;
                }

                lastFound = owned.Select(process => $"{process.Name} PID {process.Id}").ToList();
                foreach (var item in owned)
                {
                    try
                    {
                        using var process = Process.GetProcessById(item.Id);
                        if (!process.HasExited) process.Kill(entireProcessTree: true);
                        await process.WaitForExitAsync().WaitAsync(TimeSpan.FromSeconds(5));
                        Log($"Runtime Dimensimatic dihentikan: {item.Name} PID {item.Id}");
                    }
                    catch (Exception error)
                    {
                        Log($"Gagal menghentikan {item.Name} PID {item.Id}: {error.Message}");
                    }
                }

                await Task.Delay(500);
            }

            throw new InvalidOperationException("Tidak dapat menghentikan runtime milik Dimensimatic secara otomatis. Proses yang masih terdeteksi: " + string.Join(", ", lastFound) + ". Tutup proses tersebut lalu jalankan installer kembali.");
        }

        private static void DeleteOldWorkerFiles(string target)
        {
            var worker = Path.Combine(target, "automation-worker");
            foreach (var relativePath in new[] { "config", "dist", "node_modules" })
            {
                var path = Path.Combine(worker, relativePath);
                if (Directory.Exists(path)) Directory.Delete(path, true);
            }
            foreach (var relativePath in new[] { "package.json", "package-lock.json", ".env.install" })
            {
                var path = Path.Combine(worker, relativePath);
                if (File.Exists(path)) File.Delete(path);
            }
            foreach (var pidFile in new[] {
                "storage\\app\\automation-worker.pid",
                "storage\\app\\release-queue-worker.pid",
                "storage\\app\\status-queue-worker.pid",
                "storage\\app\\cms-queue-worker.pid",
            })
            {
                var path = Path.Combine(target, pidFile);
                if (File.Exists(path)) File.Delete(path);
                if (File.Exists(path + ".logs.json")) File.Delete(path + ".logs.json");
            }
            Log("Worker lama dihentikan dan file kode worker lama dihapus sebelum ekstraksi update.");
        }

        private static bool BelongsToInstallation(Process process, string normalizedTarget)
        {
            try
            {
                var executable = process.MainModule?.FileName;
                return !string.IsNullOrWhiteSpace(executable) && Path.GetFullPath(executable).StartsWith(normalizedTarget, StringComparison.OrdinalIgnoreCase);
            }
            catch { return false; }
        }

        private static async Task WaitOrKill(Process process)
        {
            try { await process.WaitForExitAsync().WaitAsync(TimeSpan.FromSeconds(8)); }
            catch { process.Kill(entireProcessTree: true); await process.WaitForExitAsync().WaitAsync(TimeSpan.FromSeconds(8)); }
        }

        private static void TryKill(Process process)
        {
            try { if (!process.HasExited) process.Kill(entireProcessTree: true); } catch { }
        }

        private static bool IsFileLocked(string path)
        {
            if (!File.Exists(path)) return false;
            try { using var stream = File.Open(path, FileMode.Open, FileAccess.ReadWrite, FileShare.None); return false; }
            catch (IOException) { return true; }
            catch (UnauthorizedAccessException) { return true; }
        }
        private static void PrepareConfiguration(string target, bool isNewInstallation)
        {
            var appEnvPath = Path.Combine(target, ".env");
            var workerEnvPath = Path.Combine(target, "automation-worker", ".env");
            string hmac;

            // APP_KEY encrypts credentials, browser sessions, and protected asset
            // URLs in the persistent SQLite database. Never rotate it during an
            // upgrade/reinstall, otherwise Laravel can no longer decrypt them.
            if (File.Exists(appEnvPath))
            {
                var existingApp = File.ReadAllText(appEnvPath);
                hmac = ReadEnvironmentValue(existingApp, "AUTOMATION_HMAC_KEY");
                if (string.IsNullOrWhiteSpace(hmac))
                {
                    hmac = Convert.ToHexString(RandomNumberGenerator.GetBytes(32)).ToLowerInvariant();
                    existingApp = UpsertEnvironmentValue(existingApp, "AUTOMATION_HMAC_KEY", hmac);
                }

                var installTemplatePath = Path.Combine(target, ".env.install");
                if (File.Exists(installTemplatePath))
                {
                    var newVersion = ReadEnvironmentValue(File.ReadAllText(installTemplatePath), "DIMENSIMATIC_APP_VERSION");
                    if (!string.IsNullOrWhiteSpace(newVersion))
                    {
                        existingApp = UpsertEnvironmentValue(existingApp, "DIMENSIMATIC_APP_VERSION", newVersion);
                    }
                }

                existingApp = UpsertEnvironmentValue(existingApp, "DIMENSIMATIC_UPDATE_REPOSITORY", "fachry2204/dimensimatic");
                File.WriteAllText(appEnvPath, existingApp);
            }
            else
            {
                hmac = Convert.ToHexString(RandomNumberGenerator.GetBytes(32)).ToLowerInvariant();
                var appKey = "base64:" + Convert.ToBase64String(RandomNumberGenerator.GetBytes(32));
                var app = File.ReadAllText(Path.Combine(target, ".env.install")).Replace("__APP_KEY__", appKey).Replace("__HMAC_KEY__", hmac);
                File.WriteAllText(appEnvPath, app);
            }

            if (File.Exists(workerEnvPath))
            {
                var existingWorker = File.ReadAllText(workerEnvPath);
                File.WriteAllText(workerEnvPath, UpsertEnvironmentValue(existingWorker, "AUTOMATION_HMAC_KEY", hmac));
            }
            else
            {
                var worker = File.ReadAllText(Path.Combine(target, "automation-worker", ".env.install")).Replace("__HMAC_KEY__", hmac);
                File.WriteAllText(workerEnvPath, worker);
            }
            // Browser requests and queue workers run concurrently in the
            // desktop package. Keeping sessions in SQLite can invalidate the
            // CSRF session during login when SQLite is busy, producing a 419
            // Page Expired screen. Migrate legacy desktop installs to Laravel
            // file sessions without touching credentials or the app key.
            ConfigureDesktopSessionStorage(appEnvPath);
            ConfigurePortablePhp(target);
            var database = Path.Combine(target, "database", "database.sqlite");
            if (isNewInstallation)
            {
                // A new installation must never inherit SQLite/WAL files that
                // may have been left in the bundled build workspace. A WAL
                // without its matching database makes integrity_check fail.
                foreach (var file in Directory.GetFiles(Path.GetDirectoryName(database)!, "database.sqlite*"))
                    File.Delete(file);
            }
            if (!File.Exists(database)) File.WriteAllBytes(database, Array.Empty<byte>());
            foreach (var directory in new[] { "storage\\logs", "storage\\framework\\cache", "storage\\framework\\sessions", "storage\\framework\\views", "storage\\app\\private", "bootstrap\\cache", "automation-worker\\storage\\profiles", "automation-worker\\storage\\screenshots" }) Directory.CreateDirectory(Path.Combine(target, directory));
        }

        private static string ReadEnvironmentValue(string contents, string key)
        {
            var match = Regex.Match(contents, $@"(?m)^\s*{Regex.Escape(key)}\s*=\s*(.*)\s*$");
            return match.Success ? match.Groups[1].Value.Trim().Trim('"') : string.Empty;
        }

        private static string UpsertEnvironmentValue(string contents, string key, string value)
        {
            var pattern = $@"(?m)^\s*{Regex.Escape(key)}\s*=.*$";
            if (Regex.IsMatch(contents, pattern)) return Regex.Replace(contents, pattern, $"{key}={value}");
            return contents.TrimEnd() + Environment.NewLine + $"{key}={value}" + Environment.NewLine;
        }

        private static void ConfigureDesktopSessionStorage(string appEnvPath)
        {
            var contents = File.ReadAllText(appEnvPath);
            var driver = ReadEnvironmentValue(contents, "SESSION_DRIVER");
            var changed = false;

            if (string.IsNullOrWhiteSpace(driver) || string.Equals(driver, "database", StringComparison.OrdinalIgnoreCase))
            {
                contents = UpsertEnvironmentValue(contents, "SESSION_DRIVER", "file");
                changed = true;
            }

            if (string.IsNullOrWhiteSpace(ReadEnvironmentValue(contents, "SESSION_COOKIE")))
            {
                contents = UpsertEnvironmentValue(contents, "SESSION_COOKIE", "dimensimatic_session");
                changed = true;
            }

            if (changed) File.WriteAllText(appEnvPath, contents);

            // A cached Laravel config would retain the old database session
            // driver even after .env is corrected. It is safe to remove only
            // this generated cache; all source configuration remains intact.
            var configCache = Path.Combine(Path.GetDirectoryName(appEnvPath)!, "bootstrap", "cache", "config.php");
            if (File.Exists(configCache)) File.Delete(configCache);
        }

        private static void ConfigurePortablePhp(string target)
        {
            var iniPath = Path.Combine(target, "runtime", "php", "php.ini");
            var certificatePath = Path.Combine(target, "runtime", "php", "extras", "ssl", "cacert.pem");
            if (!File.Exists(certificatePath)) throw new InvalidOperationException("Sertifikat CA untuk koneksi HTTPS tidak ditemukan dalam paket instalasi.");
            var escapedCertificatePath = certificatePath.Replace("\\", "/");
            var ini = File.ReadAllText(iniPath);
            ini = System.Text.RegularExpressions.Regex.Replace(ini, @"(?m)^\s*curl\.cainfo\s*=.*$", "curl.cainfo=\"" + escapedCertificatePath + "\"");
            ini = System.Text.RegularExpressions.Regex.Replace(ini, @"(?m)^\s*openssl\.cafile\s*=.*$", "openssl.cafile=\"" + escapedCertificatePath + "\"");
            File.WriteAllText(iniPath, ini);
        }
        private static async Task EnsureHealthyDatabase(string target)
        {
            var directory = Path.Combine(target, "database");
            var database = Path.Combine(directory, "database.sqlite");
            if (!File.Exists(database) || new FileInfo(database).Length == 0) return;
            var php = Path.Combine(target, "runtime", "php", "php.exe");
            if (await DatabaseIsHealthy(php, database)) return;

            string? backup = null;
            foreach (var candidate in Directory.GetFiles(directory, "database.sqlite.backup*")
                .Concat(Directory.GetFiles(directory, "database.sqlite.bak*"))
                .OrderByDescending(File.GetLastWriteTimeUtc))
            {
                if (!await DatabaseIsHealthy(php, candidate)) continue;
                backup = candidate;
                break;
            }
            if (backup is null)
                throw new InvalidOperationException(
                    "Database Dimensimatic lama rusak (SQLite integrity_check gagal). Installer tidak akan menghapus atau menimpanya. " +
                    "Simpan folder database dan hubungi operator untuk pemulihan; tidak ditemukan cadangan sehat.");

            var confirmation = MessageBox.Show(
                "Database Dimensimatic saat ini rusak, tetapi ditemukan cadangan sehat:\n" + backup +
                "\n\nTanggal cadangan: " + File.GetLastWriteTime(backup).ToString("yyyy-MM-dd HH:mm") +
                "\n\nMemulihkan cadangan akan menghilangkan perubahan setelah tanggal tersebut. " +
                "File rusak akan disimpan dengan akhiran .corrupt-... untuk kemungkinan pemulihan lanjutan.\n\n" +
                "Pulihkan dari cadangan dan lanjutkan instalasi? Pilih No untuk membatalkan tanpa mengubah database.",
                "Konfirmasi pemulihan database", MessageBoxButtons.YesNo, MessageBoxIcon.Warning, MessageBoxDefaultButton.Button2);
            if (confirmation != DialogResult.Yes)
                throw new InvalidOperationException("Instalasi dibatalkan. Database lama tetap tersimpan tanpa perubahan.");

            var suffix = ".corrupt-" + DateTime.Now.ToString("yyyyMMdd-HHmmss") + "-" + Guid.NewGuid().ToString("N")[..8];
            var replacement = database + ".restoring-" + Guid.NewGuid().ToString("N");
            File.Copy(backup, replacement);
            if (!await DatabaseIsHealthy(php, replacement))
            {
                File.Delete(replacement);
                throw new InvalidOperationException("Salinan cadangan gagal pemeriksaan integritas; database lama tidak diubah.");
            }
            var original = database + suffix;
            var movedSidecars = new List<(string Original, string Saved)>();
            File.Move(database, original);
            try
            {
                foreach (var sidecar in new[] { database + "-wal", database + "-shm" })
                    if (File.Exists(sidecar))
                    {
                        File.Move(sidecar, sidecar + suffix);
                        movedSidecars.Add((sidecar, sidecar + suffix));
                    }
                File.Move(replacement, database);
                if (!await DatabaseIsHealthy(php, database))
                    throw new InvalidOperationException("Database hasil pemulihan tidak lolos pemeriksaan integritas.");
            }
            catch
            {
                if (File.Exists(database)) File.Move(database, database + ".failed-restore-" + Guid.NewGuid().ToString("N"));
                File.Copy(original, database);
                foreach (var sidecar in movedSidecars)
                    if (File.Exists(sidecar.Saved)) File.Copy(sidecar.Saved, sidecar.Original);
                if (File.Exists(replacement)) File.Delete(replacement);
                throw;
            }
        }

        private static async Task<bool> DatabaseIsHealthy(string php, string database)
        {
            const string code = "try { $db = new PDO('sqlite:file:' . str_replace('\\\\', '/', $argv[1]) . '?mode=ro'); " +
                "$result = $db->query('PRAGMA integrity_check')->fetchColumn(); " +
                "exit($result === 'ok' ? 0 : 2); } catch (Throwable $error) { exit(2); }";
            var info = new ProcessStartInfo(php) { UseShellExecute = false, CreateNoWindow = true };
            info.ArgumentList.Add("-r");
            info.ArgumentList.Add(code);
            info.ArgumentList.Add(database);
            using var process = Process.Start(info) ?? throw new InvalidOperationException("Pemeriksaan SQLite gagal dimulai.");
            try { await process.WaitForExitAsync().WaitAsync(TimeSpan.FromSeconds(30)); }
            catch (TimeoutException) { process.Kill(true); return false; }
            return process.ExitCode == 0;
        }
        private static async Task Run(string exe, string args, string cwd) { var p = Process.Start(new ProcessStartInfo(exe, args) { WorkingDirectory = cwd, UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true, RedirectStandardError = true }) ?? throw new InvalidOperationException("Proses instalasi gagal dimulai."); var stdout = p.StandardOutput.ReadToEndAsync(); var stderr = p.StandardError.ReadToEndAsync(); await p.WaitForExitAsync(); if (p.ExitCode != 0) throw new InvalidOperationException((await stderr) + Environment.NewLine + (await stdout)); }
        private static void CreateShortcut(string path, string target, string cwd) { Directory.CreateDirectory(Path.GetDirectoryName(path)!); var shellType = Type.GetTypeFromProgID("WScript.Shell")!; dynamic shell = Activator.CreateInstance(shellType)!; dynamic shortcut = shell.CreateShortcut(path); shortcut.TargetPath = target; shortcut.WorkingDirectory = cwd; var iconPath = Path.Combine(cwd, "public", "favicon.ico"); shortcut.IconLocation = File.Exists(iconPath) ? iconPath : target + ",0"; shortcut.Description = "Dimensimatic Release Automation"; shortcut.Save(); System.Runtime.InteropServices.Marshal.FinalReleaseComObject(shortcut); System.Runtime.InteropServices.Marshal.FinalReleaseComObject(shell); }
    }
}
