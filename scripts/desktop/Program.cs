using System.Diagnostics;
using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;
using System.Management;

namespace Dimensimatic.Desktop;

internal static class Program
{
    private const int AppPort = 8012, WorkerPort = 3112;
    [STAThread]
    private static void Main(string[] args)
    {
        ApplicationConfiguration.Initialize();
        var root = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar);
        if (args.Contains("--check")) { Environment.ExitCode = Required(root) ? 0 : 2; return; }
        using var instance = new Mutex(true, @"Local\Dimensimatic.Desktop.Singleton", out var isFirstInstance);
        if (!isFirstInstance)
        {
            MessageBox.Show("Dimensimatic sudah berjalan. Tutup jendela yang aktif sebelum membukanya kembali.", "Dimensimatic", MessageBoxButtons.OK, MessageBoxIcon.Information);
            return;
        }
        Application.Run(new MainWindow(root));
    }

    private static string Php(string root) => Path.Combine(root, "runtime", "php", "php.exe");
    private static string Node(string root) => Path.Combine(root, "runtime", "node", "node.exe");
    private static bool Required(string root) => File.Exists(Path.Combine(root, ".env")) &&
        File.Exists(Path.Combine(root, "automation-worker", ".env")) && File.Exists(Path.Combine(root, "automation-worker", "dist", "server.js")) &&
        File.Exists(Php(root)) && File.Exists(Node(root));

    private sealed class MainWindow : Form
    {
        private readonly string root;
        private readonly Label status = new() { Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleCenter, Text = "Menjalankan Dimensimatic...", Font = new Font("Segoe UI", 12) };
        private readonly WebView2 view = new() { Dock = DockStyle.Fill, Visible = false };
        private readonly System.Windows.Forms.Timer serviceMonitor = new() { Interval = 5000 };
        private readonly Dictionary<string, Process> ownedServices = new(StringComparer.OrdinalIgnoreCase);
        private string systemName = "Dimensimatic";
        private Icon? defaultWindowIcon;
        private string automationKey = "";
        private string browserExecutable = "";
        private bool recoveringServices;
        private bool shuttingDown;
        private bool closeApproved;

        public MainWindow(string root)
        {
            this.root = root; Text = "Dimensimatic v1.1.106"; Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath); Width = 1440; Height = 900; MinimumSize = new Size(1000, 680); StartPosition = FormStartPosition.CenterScreen;
            defaultWindowIcon = Icon is null ? null : (Icon)Icon.Clone();
            Controls.Add(view); Controls.Add(status); Shown += async (_, _) => await StartAsync();
            serviceMonitor.Tick += async (_, _) => await RecoverServicesAsync();
            FormClosing += ConfirmCloseAndStopServices;
        }

        private async Task StartAsync()
        {
            try
            {
                if (!Required(root)) throw new InvalidOperationException("Instalasi Dimensimatic tidak lengkap. Jalankan ulang installer Dimensimatic Setup.");
                Directory.CreateDirectory(Path.Combine(root, "storage", "logs"));
                automationKey = Env(Path.Combine(root, "automation-worker", ".env"), "AUTOMATION_HMAC_KEY");
                browserExecutable = FindBrowser();
                if (string.IsNullOrWhiteSpace(browserExecutable)) throw new InvalidOperationException("Google Chrome atau Microsoft Edge tidak ditemukan. Instal salah satunya lalu buka kembali Dimensimatic.");
                if (string.IsNullOrWhiteSpace(automationKey)) automationKey = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(Env(Path.Combine(root, ".env"), "APP_KEY") + "|dimensimatic-automation-hmac"))).ToLowerInvariant();
                status.Text = "Membersihkan service lama...";
                StopOwnedServices();
                await WaitPortsClosed();
                if (shuttingDown) return;
                RecoverInterruptedAutomation();
                if (shuttingDown) return;
                StartAllServices();
                status.Text = "Menunggu service lokal..."; await WaitPort(AppPort); await WaitPort(WorkerPort); await VerifyWorkerVersion(); await WaitQueueWorker("release-automation"); await WaitQueueWorker("status-checks"); await WaitQueueWorker("cms-verification"); await WaitWebApplication();
                if (shuttingDown) return;
                var environment = await CoreWebView2Environment.CreateAsync(userDataFolder: Path.Combine(root, "storage", "app", "webview2"));
                await view.EnsureCoreWebView2Async(environment);
                view.CoreWebView2.Settings.AreDevToolsEnabled = false;
                view.CoreWebView2.WebMessageReceived += (_, e) =>
                {
                    try
                    {
                        using var message = System.Text.Json.JsonDocument.Parse(e.WebMessageAsJson);
                        var rootMessage = message.RootElement.ValueKind == System.Text.Json.JsonValueKind.String
                            ? System.Text.Json.JsonDocument.Parse(message.RootElement.GetString() ?? "{}").RootElement.Clone()
                            : message.RootElement;
                        if (rootMessage.TryGetProperty("type", out var brandingType)
                            && brandingType.GetString() == "dimensimatic-branding-updated")
                        {
                            ApplySystemBranding(rootMessage);
                            return;
                        }
                        if (rootMessage.TryGetProperty("type", out var type)
                            && type.GetString() == "dimensimatic-update-ready"
                            && rootMessage.TryGetProperty("path", out var installerPath))
                            TryLaunchUpdateInstaller(installerPath.GetString());
                    }
                    catch (Exception error)
                    {
                        MessageBox.Show("Installer update gagal dijalankan: " + error.Message, "Dimensimatic", MessageBoxButtons.OK, MessageBoxIcon.Error);
                    }
                };
                view.CoreWebView2.NavigationStarting += (_, e) =>
                {
                    if (IsExternalUrl(e.Uri))
                    {
                        e.Cancel = true;
                        OpenExternalUrl(e.Uri);
                    }
                };
                view.CoreWebView2.NewWindowRequested += (_, e) =>
                {
                    e.Handled = true;
                    if (IsExternalUrl(e.Uri)) OpenExternalUrl(e.Uri);
                    else view.CoreWebView2.Navigate(e.Uri);
                };
                view.Source = new Uri($"http://127.0.0.1:{AppPort}/login"); status.Visible = false; view.Visible = true;
                serviceMonitor.Start();
            }
            catch (Exception e)
            {
                status.Text = "Dimensimatic gagal dijalankan.\n\n" + e.Message;
                File.WriteAllText(Path.Combine(root, "storage", "logs", "launcher-error.log"), e.ToString());
                MessageBox.Show(e.Message, "Dimensimatic", MessageBoxButtons.OK, MessageBoxIcon.Error);
            }
        }

        [System.Runtime.InteropServices.DllImport("user32.dll")]
        private static extern bool DestroyIcon(IntPtr icon);

        private void ApplySystemBranding(System.Text.Json.JsonElement branding)
        {
            if (branding.TryGetProperty("name", out var nameValue))
            {
                var name = nameValue.GetString()?.Trim();
                if (!string.IsNullOrEmpty(name) && name.Length <= 80)
                {
                    systemName = name;
                    Text = systemName + " v1.1.106";
                }
            }
            if (!branding.TryGetProperty("favicon", out var faviconValue)) return;
            var favicon = faviconValue.GetString() ?? "";
            if (!favicon.StartsWith("data:image/", StringComparison.Ordinal) || favicon.Length > 750_000)
            {
                if (defaultWindowIcon is not null) Icon = (Icon)defaultWindowIcon.Clone();
                return;
            }
            var separator = favicon.IndexOf(";base64,", StringComparison.Ordinal);
            if (separator < 0) return;
            try
            {
                using var stream = new MemoryStream(Convert.FromBase64String(favicon[(separator + 8)..]));
                if (favicon.StartsWith("data:image/png;", StringComparison.Ordinal))
                {
                    using var bitmap = new Bitmap(stream);
                    var handle = bitmap.GetHicon();
                    try { using var icon = System.Drawing.Icon.FromHandle(handle); Icon = (Icon)icon.Clone(); }
                    finally { DestroyIcon(handle); }
                }
                else
                {
                    using var icon = new Icon(stream);
                    Icon = (Icon)icon.Clone();
                }
            }
            catch { /* Keep the current icon if Windows cannot decode the upload. */ }
        }

        private void StartAllServices()
        {
            Start("app", Php(root), ["artisan", "serve", "--host=127.0.0.1", $"--port={AppPort}"], root, automationKey);
            Start("scheduler", Php(root), ["artisan", "schedule:work", "--no-interaction"], root, automationKey);
            StartQueueWorker("release-automation,default", "release-queue-worker.pid", "release-worker.log", "release-worker-error.log");
            StartQueueWorker("status-checks", "status-queue-worker.pid", "status-worker.log", "status-worker-error.log");
            StartQueueWorker("cms-verification", "cms-queue-worker.pid", "cms-worker.log", "cms-worker-error.log");
            Start("worker", Node(root), ["--env-file=.env", "dist/server.js"], Path.Combine(root, "automation-worker"), automationKey);
        }

        private void RecoverInterruptedAutomation()
        {
            var info = new ProcessStartInfo(Php(root), "artisan dimensimatic:recover-interrupted")
            {
                WorkingDirectory = root,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
            };
            info.Environment["AUTOMATION_HMAC_KEY"] = automationKey;
            using var process = Process.Start(info) ?? throw new InvalidOperationException("Pemulihan antrean Dimensimatic gagal dimulai.");
            process.WaitForExit(30_000);
            if (!process.HasExited)
            {
                process.Kill(true);
                throw new InvalidOperationException("Pemulihan antrean Dimensimatic melewati batas waktu.");
            }
            if (process.ExitCode != 0)
            {
                var error = process.StandardError.ReadToEnd();
                throw new InvalidOperationException("Pemulihan antrean Dimensimatic gagal. " + error);
            }
        }

        private async Task RecoverServicesAsync()
        {
            if (recoveringServices || shuttingDown) return;
            recoveringServices = true;
            try
            {
                if (!PortOpen(AppPort)) Start("app", Php(root), ["artisan", "serve", "--host=127.0.0.1", $"--port={AppPort}"], root, automationKey);
                // Worker lifecycle belongs to the dashboard after startup.
                // Restarting workers every five seconds races with Stop and
                // pending collection reset, creating duplicate consumers.
                await Task.CompletedTask;
            }
            finally { recoveringServices = false; }
        }

        private void TryLaunchUpdateInstaller(string? installerPath)
        {
            if (string.IsNullOrWhiteSpace(installerPath)) throw new InvalidOperationException("Path installer update kosong.");
            var fullPath = Path.GetFullPath(installerPath);
            var updatesDirectory = Path.GetFullPath(Path.Combine(root, "storage", "app", "updates")).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
            if (!fullPath.StartsWith(updatesDirectory, StringComparison.OrdinalIgnoreCase)
                || !Path.GetFileName(fullPath).StartsWith("Dimensimatic-Setup-v", StringComparison.OrdinalIgnoreCase)
                || !string.Equals(Path.GetExtension(fullPath), ".exe", StringComparison.OrdinalIgnoreCase)
                || !File.Exists(fullPath))
                throw new InvalidOperationException("Installer update tidak valid atau tidak ditemukan.");

            var installer = new ProcessStartInfo(fullPath)
            {
                WorkingDirectory = Path.GetDirectoryName(fullPath)!,
                UseShellExecute = true,
            };
            installer.ArgumentList.Add("--silent-update");
            installer.ArgumentList.Add("--detached");
            if (Process.Start(installer) is null)
                throw new InvalidOperationException("Windows gagal memulai installer update.");
            // An in-app update already represents explicit approval to close;
            // do not interrupt the update flow with the normal close prompt.
            closeApproved = true;
            BeginInvoke(Close);
        }

        private void ConfirmCloseAndStopServices(object? sender, FormClosingEventArgs e)
        {
            if (closeApproved)
            {
                ShutdownServices();
                return;
            }

            var choice = MessageBox.Show(
                this,
                "Tutup " + systemName + " dan hentikan service milik aplikasi ini? Service akan berjalan kembali saat aplikasi dibuka lagi.",
                "Tutup " + systemName + "?",
                MessageBoxButtons.YesNo,
                MessageBoxIcon.Question,
                MessageBoxDefaultButton.Button2);
            if (choice != DialogResult.Yes)
            {
                e.Cancel = true;
                return;
            }

            // Keep the form alive until owned child processes and ports have
            // been stopped; this makes installer relaunches safe immediately.
            e.Cancel = true;
            closeApproved = true;
            Enabled = false;
            status.Visible = true;
            status.Text = "Menghentikan service " + systemName + "...";
            ShutdownServices();
            BeginInvoke(Close);
        }

        private void ShutdownServices()
        {
            if (shuttingDown) return;
            shuttingDown = true;
            serviceMonitor.Stop();
            StopChildProcesses();
            StopOwnedServices();
            for (var i = 0; i < 80 && (PortOpen(AppPort) || PortOpen(WorkerPort)); i++) Thread.Sleep(100);
        }

        private static void StopChildProcesses()
        {
            try
            {
                var currentId = Environment.ProcessId;
                using var query = new ManagementObjectSearcher($"SELECT ProcessId FROM Win32_Process WHERE ParentProcessId={currentId}");
                foreach (ManagementObject child in query.Get())
                {
                    try { Process.GetProcessById(Convert.ToInt32(child["ProcessId"])).Kill(true); } catch { }
                }
            }
            catch { }
        }

        private void StopOwnedServices()
        {
            // Stop the exact Process instances started by this desktop host.
            // Detached `cmd start /B` launches outlived the window and lost
            // ownership of Node and the Playwright browser child processes.
            foreach (var process in ownedServices.Values.ToArray())
                KillProcessTree(process);
            ownedServices.Clear();

            var appDirectory = Path.Combine(root, "storage", "app");
            Directory.CreateDirectory(appDirectory);
            foreach (var name in WorkerPidFiles)
            {
                var pidPath = Path.Combine(appDirectory, name);
                if (File.Exists(pidPath) && int.TryParse(File.ReadAllText(pidPath).Trim(), out var pid))
                    KillBundledProcessTree(pid);
            }

            // Also stop orphaned processes from older builds, but only when
            // they use this installation's private PHP or Node executable.
            try
            {
                using var query = new ManagementObjectSearcher("SELECT ProcessId, Name, ExecutablePath FROM Win32_Process WHERE Name='php.exe' OR Name='node.exe'");
                foreach (ManagementObject process in query.Get())
                {
                    if (!IsBundledRuntime((string?)process["ExecutablePath"])) continue;
                    try { KillProcessTree(Process.GetProcessById(Convert.ToInt32(process["ProcessId"]))); } catch { }
                }
            }
            catch { }

            for (var attempt = 0; attempt < 30 && HasBundledRuntimeProcesses(); attempt++) Thread.Sleep(100);
            CleanupWorkerMarkers(appDirectory);
        }

        private static readonly string[] WorkerPidFiles = [
            "desktop-app.pid", "desktop-scheduler.pid", "automation-worker-desktop.pid",
            "automation-worker.pid", "release-queue-worker.pid", "status-queue-worker.pid", "cms-queue-worker.pid", "laravel-server.pid",
        ];

        private void KillBundledProcessTree(int processId)
        {
            try
            {
                var process = Process.GetProcessById(processId);
                if (IsBundledRuntime(process.MainModule?.FileName)) KillProcessTree(process);
            }
            catch { }
        }

        private static void KillProcessTree(Process process)
        {
            try { if (!process.HasExited) process.Kill(entireProcessTree: true); } catch { }
            try { process.WaitForExit(5_000); } catch { }
        }

        private bool HasBundledRuntimeProcesses()
        {
            try
            {
                using var query = new ManagementObjectSearcher("SELECT ExecutablePath FROM Win32_Process WHERE Name='php.exe' OR Name='node.exe'");
                return query.Get().Cast<ManagementObject>().Any(process => IsBundledRuntime((string?)process["ExecutablePath"]));
            }
            catch { return false; }
        }

        private bool IsBundledRuntime(string? executable)
        {
            if (string.IsNullOrWhiteSpace(executable)) return false;
            return string.Equals(Path.GetFullPath(executable), Path.GetFullPath(Php(root)), StringComparison.OrdinalIgnoreCase)
                || string.Equals(Path.GetFullPath(executable), Path.GetFullPath(Node(root)), StringComparison.OrdinalIgnoreCase);
        }

        private static void CleanupWorkerMarkers(string appDirectory)
        {
            foreach (var name in WorkerPidFiles)
            {
                var path = Path.Combine(appDirectory, name);
                foreach (var file in new[] { path, path + ".logs.json", path + ".lock" })
                    try { if (File.Exists(file)) File.Delete(file); } catch { }
            }

            foreach (var pattern in new[] { "automation-worker-*.cmd", "browser-worker-*.cmd", "release-queue-worker-*.cmd", "status-queue-worker-*.cmd", "cms-queue-worker-*.cmd", "laravel-server-*.cmd" })
                foreach (var file in Directory.EnumerateFiles(appDirectory, pattern))
                    try { File.Delete(file); } catch { }
        }

        private static async Task WaitPortsClosed()
        {
            for (var i = 0; i < 40 && (PortOpen(AppPort) || PortOpen(WorkerPort)); i++) await Task.Delay(250);
        }

        private bool QueueRunning(string queue)
        {
            var pidFile = Path.Combine(root, "storage", "app", queue == "cms-verification" ? "cms-queue-worker.pid" : queue == "status-checks" ? "status-queue-worker.pid" : "release-queue-worker.pid");
            if (File.Exists(pidFile) && int.TryParse(File.ReadAllText(pidFile).Trim(), out var pid))
            {
                try { using var process = Process.GetProcessById(pid); return !process.HasExited && IsBundledRuntime(process.MainModule?.FileName); } catch { try { File.Delete(pidFile); } catch { } }
            }
            try { using var q = new ManagementObjectSearcher("SELECT CommandLine, ExecutablePath FROM Win32_Process WHERE Name='php.exe'"); return q.Get().Cast<ManagementObject>().Any(p => { var command = (string?)p["CommandLine"] ?? ""; return IsBundledRuntime((string?)p["ExecutablePath"]) && command.Contains("queue:work", StringComparison.OrdinalIgnoreCase) && command.Contains(queue, StringComparison.OrdinalIgnoreCase); }); }
            catch { return false; }
        }
        private void StartQueueWorker(string queue, string pidFileName, string outputLog, string errorLog)
        {
            var pidFile = Path.Combine(root, "storage", "app", pidFileName);
            var wrapper = Path.Combine(root, "bootstrap", "app.php");
            var logs = Path.Combine(root, "storage", "logs");
            var launchId = DateTime.UtcNow.ToString("yyyyMMdd-HHmmss") + "-" + Guid.NewGuid().ToString("N");
            var launcher = Path.Combine(root, "storage", "app", Path.GetFileNameWithoutExtension(pidFileName) + "-" + launchId + ".cmd");
            outputLog = Path.GetFileNameWithoutExtension(outputLog) + "-" + launchId + ".log";
            errorLog = Path.GetFileNameWithoutExtension(errorLog) + "-" + launchId + ".log";
            File.WriteAllText(pidFile + ".logs.json", System.Text.Json.JsonSerializer.Serialize(new { stdout = Path.Combine(logs, outputLog), stderr = Path.Combine(logs, errorLog) }));
            Directory.CreateDirectory(Path.GetDirectoryName(launcher)!);
            var command = "start \"\" /B " + QuoteForCmd(Php(root)) + " " + QuoteForCmd(wrapper)
                + " --queue-runner " + QuoteForCmd(pidFile)
                + " queue:work database --queue=" + queue + " --sleep=2 --tries=3 --timeout=2700"
                + " > " + QuoteForCmd(Path.Combine(logs, outputLog))
                + " 2> " + QuoteForCmd(Path.Combine(logs, errorLog));
            File.WriteAllText(launcher, "@echo off\r\n" + command + "\r\n");
            var info = new ProcessStartInfo(Environment.GetEnvironmentVariable("ComSpec")!) { WorkingDirectory = root, UseShellExecute = false, CreateNoWindow = true, WindowStyle = ProcessWindowStyle.Hidden };
            info.ArgumentList.Add("/D"); info.ArgumentList.Add("/S"); info.ArgumentList.Add("/C"); info.ArgumentList.Add(launcher);
            info.Environment["AUTOMATION_HMAC_KEY"] = automationKey;
            info.Environment["PLAYWRIGHT_SERVICE_URL"] = $"http://127.0.0.1:{WorkerPort}";
            info.Environment["PLAYWRIGHT_CHROMIUM_EXECUTABLE"] = browserExecutable;
            info.Environment["PORT"] = WorkerPort.ToString();
            info.Environment["APP_URL"] = $"http://127.0.0.1:{AppPort}";
            info.Environment["PATH"] = Path.Combine(root, "runtime", "media") + Path.PathSeparator + (info.Environment["PATH"] ?? Environment.GetEnvironmentVariable("PATH") ?? "");
            Process.Start(info);
        }
        private static bool IsExternalUrl(string? value)
        {
            if (!Uri.TryCreate(value, UriKind.Absolute, out var uri)) return false;
            if (uri.Scheme is not ("http" or "https")) return false;

            return !IPAddress.IsLoopback(uri.HostNameType == UriHostNameType.IPv4 || uri.HostNameType == UriHostNameType.IPv6 ? IPAddress.Parse(uri.Host) : IPAddress.None)
                && !string.Equals(uri.Host, "localhost", StringComparison.OrdinalIgnoreCase);
        }

        private static void OpenExternalUrl(string url)
        {
            Process.Start(new ProcessStartInfo(url) { UseShellExecute = true });
        }

        private static string QuoteForCmd(string value) => "\"" + value.Replace("\"", "\"\"") + "\"";
        private void Start(string name, string exe, string[] args, string cwd, string key)
        {
            if (ownedServices.TryGetValue(name, out var existing))
            {
                if (!existing.HasExited) return;
                existing.Dispose();
                ownedServices.Remove(name);
            }

            var logs = Path.Combine(root, "storage", "logs");
            Directory.CreateDirectory(logs);
            var info = new ProcessStartInfo(exe) { WorkingDirectory = cwd, UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true, RedirectStandardError = true };
            foreach (var argument in args) info.ArgumentList.Add(argument);
            info.Environment["AUTOMATION_HMAC_KEY"] = key; info.Environment["PLAYWRIGHT_SERVICE_URL"] = $"http://127.0.0.1:{WorkerPort}";
            info.Environment["PLAYWRIGHT_CHROMIUM_EXECUTABLE"] = browserExecutable;
            info.Environment["PORT"] = WorkerPort.ToString();
            info.Environment["APP_URL"] = $"http://127.0.0.1:{AppPort}";
            info.Environment["PATH"] = Path.Combine(root, "runtime", "media") + Path.PathSeparator + (info.Environment["PATH"] ?? Environment.GetEnvironmentVariable("PATH") ?? "");
            var outputPath = Path.Combine(logs, $"launcher-{name}.log");
            var errorPath = Path.Combine(logs, $"launcher-{name}-error.log");
            var process = new Process { StartInfo = info, EnableRaisingEvents = true };
            process.OutputDataReceived += (_, eventArgs) => AppendLogLine(outputPath, eventArgs.Data);
            process.ErrorDataReceived += (_, eventArgs) => AppendLogLine(errorPath, eventArgs.Data);
            if (!process.Start()) throw new InvalidOperationException($"Layanan {name} gagal dimulai.");
            process.BeginOutputReadLine();
            process.BeginErrorReadLine();
            ownedServices[name] = process;

            var appDirectory = Path.Combine(root, "storage", "app");
            Directory.CreateDirectory(appDirectory);
            var pidFileName = name == "worker" ? "automation-worker-desktop.pid" : $"desktop-{name}.pid";
            File.WriteAllText(Path.Combine(appDirectory, pidFileName), process.Id.ToString());
        }

        private static void AppendLogLine(string path, string? line)
        {
            if (line is null) return;
            try { File.AppendAllText(path, line + Environment.NewLine); } catch { }
        }
        private async Task VerifyWorkerVersion()
        {
            using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(5) };
            var timestamp = DateTimeOffset.UtcNow.ToUnixTimeSeconds().ToString();
            var nonce = Guid.NewGuid().ToString("N");
            var signature = Convert.ToHexString(HMACSHA256.HashData(Encoding.UTF8.GetBytes(automationKey), Encoding.UTF8.GetBytes(timestamp + "." + nonce + "."))).ToLowerInvariant();
            using var request = new HttpRequestMessage(HttpMethod.Get, $"http://127.0.0.1:{WorkerPort}/v1/runtime");
            request.Headers.Add("X-Automation-Timestamp", timestamp);
            request.Headers.Add("X-Automation-Nonce", nonce);
            request.Headers.Add("X-Automation-Signature", signature);
            using var response = await client.SendAsync(request);
            if (!response.IsSuccessStatusCode) throw new InvalidOperationException("Worker tidak sesuai atau belum diperbarui. Pasang ulang Dimensimatic v1.1.106 lalu buka kembali.");
            using var document = System.Text.Json.JsonDocument.Parse(await response.Content.ReadAsStringAsync());
            if (document.RootElement.GetProperty("data").GetProperty("version").GetString() != "1.1.106")
                throw new InvalidOperationException("Versi worker berbeda dari aplikasi v1.1.106. Tutup aplikasi dan pasang ulang pembaruan.");
        }

        private static async Task WaitPort(int port) { for (var i = 0; i < 90; i++) { if (PortOpen(port)) return; await Task.Delay(500); } throw new InvalidOperationException($"Service port {port} tidak aktif."); }
        private async Task WaitQueueWorker(string queue) { for (var i = 0; i < 60; i++) { if (QueueRunning(queue)) return; await Task.Delay(500); } throw new InvalidOperationException($"Queue worker {queue} tidak aktif. Periksa file log worker di Pengaturan Platform."); }
        private static async Task WaitWebApplication()
        {
            using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(2) };
            string lastFailure = "tidak memberikan respons";
            for (var i = 0; i < 90; i++)
            {
                try
                {
                    using var response = await client.GetAsync($"http://127.0.0.1:{AppPort}/login");
                    if ((int)response.StatusCode < 500) return;
                    lastFailure = $"HTTP {(int)response.StatusCode}";
                }
                catch (Exception error) { lastFailure = error.Message; }
                await Task.Delay(500);
            }
            throw new InvalidOperationException($"Server lokal aktif tetapi halaman aplikasi belum sehat ({lastFailure}). Periksa launcher-error.log.");
        }
        private static bool PortOpen(int port) { try { using var c = new TcpClient(); return c.ConnectAsync("127.0.0.1", port).Wait(TimeSpan.FromMilliseconds(250)); } catch { return false; } }
        private static string Env(string path, string key) { var prefix = key + "="; var line = File.ReadLines(path).FirstOrDefault(x => x.StartsWith(prefix, StringComparison.Ordinal)); return line is null ? "" : line[prefix.Length..].Trim().Trim('"'); }
        private static string FindBrowser()
        {
            var candidates = new[] {
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "Google", "Chrome", "Application", "chrome.exe"),
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86), "Google", "Chrome", "Application", "chrome.exe"),
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86), "Microsoft", "Edge", "Application", "msedge.exe"),
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "Microsoft", "Edge", "Application", "msedge.exe"),
            };
            return candidates.FirstOrDefault(File.Exists) ?? "";
        }
    }
}
