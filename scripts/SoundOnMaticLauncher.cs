using System;
using System.Diagnostics;
using System.IO;
using System.Net.Sockets;
using System.Collections.Generic;
using System.Threading;
using System.Windows.Forms;

internal static class SoundOnMaticLauncher
{
    private const int AppPort = 8001;
    private const int WorkerPort = 3100;

    [STAThread]
    private static void Main(string[] args)
    {
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);

        string project = AppDomain.CurrentDomain.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar);
        string envFile = Path.Combine(project, ".env");
        string workerDirectory = Path.Combine(project, "automation-worker");
        string workerEnv = Path.Combine(workerDirectory, ".env");
        string workerEntry = Path.Combine(workerDirectory, "dist", "server.js");
        string php = @"D:\xampp\php\php.exe";
        string node = @"C:\Program Files\nodejs\node.exe";
        string pidFile = Path.Combine(project, "storage", "logs", "local-services.pids");
        bool noBrowser = args.Length > 0 && string.Equals(args[0], "--no-browser", StringComparison.OrdinalIgnoreCase);

        if (args.Length > 0 && string.Equals(args[0], "--check", StringComparison.OrdinalIgnoreCase))
        {
            Environment.ExitCode =
                File.Exists(envFile) && File.Exists(workerEnv) && File.Exists(workerEntry) &&
                File.Exists(php) && File.Exists(node) ? 0 : 2;
            return;
        }

        if (!File.Exists(envFile) || !File.Exists(workerEnv) || !File.Exists(workerEntry))
        {
            MessageBox.Show(
                "SoundOnMatic.exe harus berada di folder utama proyek SoundOnMatic.",
                "SoundOnMatic",
                MessageBoxButtons.OK,
                MessageBoxIcon.Error);
            return;
        }

        try
        {
            NormalizePathEnvironment();
            string hmacKey = ReadEnvValue(workerEnv, "AUTOMATION_HMAC_KEY");
            if (string.IsNullOrWhiteSpace(hmacKey))
                throw new InvalidOperationException("AUTOMATION_HMAC_KEY belum tersedia di automation-worker\\.env.");

            var existing = ReadPids(pidFile);
            int appPid = GetLivePid(existing, "app");
            int queuePid = GetLivePid(existing, "queue");
            int statusQueuePid = GetLivePid(existing, "status-queue");
            int workerPid = GetLivePid(existing, "worker");

            if (appPid == 0)
            {
                appPid = StartHidden("app", php, "artisan serve --host=127.0.0.1 --port=" + AppPort, project, hmacKey);
            }
            if (queuePid == 0)
                queuePid = StartHidden("queue", php, "artisan queue:work database --queue=release-automation,default --sleep=3 --tries=3 --timeout=900", project, hmacKey);
            if (statusQueuePid == 0)
                statusQueuePid = StartHidden("status-queue", php, "artisan queue:work database --queue=status-checks --sleep=1 --tries=3 --timeout=2700", project, hmacKey);
            if (workerPid == 0)
                workerPid = StartHidden("worker", node, "--env-file=.env dist/server.js", workerDirectory, hmacKey);

            WritePids(pidFile, appPid, queuePid, statusQueuePid, workerPid);

            WaitForPort(AppPort, 45);
            WaitForPort(WorkerPort, 45);

            if (!IsPortOpen(AppPort))
            {
                throw new InvalidOperationException(
                    "Server tidak aktif setelah 45 detik. Periksa storage\\logs\\app-service-error.log.");
            }

            if (!noBrowser)
            {
                Process.Start(new ProcessStartInfo
                {
                    FileName = "http://127.0.0.1:" + AppPort + "/login",
                    UseShellExecute = true
                });
            }
        }
        catch (Exception exception)
        {
            if (noBrowser)
            {
                File.WriteAllText(Path.Combine(project, "storage", "logs", "launcher-error.log"), exception.ToString());
                Environment.ExitCode = 1;
                return;
            }
            MessageBox.Show(
                "Gagal menjalankan SoundOnMatic.\n\n" + exception.Message,
                "SoundOnMatic",
                MessageBoxButtons.OK,
                MessageBoxIcon.Error);
        }
    }

    private static int StartHidden(string name, string executable, string arguments, string workingDirectory, string hmacKey)
    {
        string logs = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "storage", "logs");
        Directory.CreateDirectory(logs);
        string output = Path.Combine(logs, "launcher-" + name + ".log");
        string error = Path.Combine(logs, "launcher-" + name + "-error.log");
        string command = "\"\"" + executable + "\" " + arguments +
            " >> \"" + output + "\" 2>> \"" + error + "\"\"";
        var info = new ProcessStartInfo
        {
            FileName = Environment.GetEnvironmentVariable("ComSpec") ?? @"C:\Windows\System32\cmd.exe",
            Arguments = "/d /s /c " + command,
            WorkingDirectory = workingDirectory,
            UseShellExecute = false,
            CreateNoWindow = true,
            WindowStyle = ProcessWindowStyle.Hidden
        };
        info.EnvironmentVariables["AUTOMATION_HMAC_KEY"] = hmacKey;
        info.EnvironmentVariables["PLAYWRIGHT_SERVICE_URL"] = "http://127.0.0.1:" + WorkerPort;
        Process process = Process.Start(info);
        if (process == null) throw new InvalidOperationException("Tidak dapat menjalankan " + executable);
        return process.Id;
    }

    private static void WaitForPort(int port, int seconds)
    {
        var deadline = DateTime.UtcNow.AddSeconds(seconds);
        while (DateTime.UtcNow < deadline && !IsPortOpen(port)) Thread.Sleep(500);
        if (!IsPortOpen(port))
            throw new InvalidOperationException("Service pada port " + port + " tidak aktif setelah " + seconds + " detik.");
    }

    private static string ReadEnvValue(string path, string key)
    {
        foreach (string line in File.ReadAllLines(path))
        {
            if (line.StartsWith(key + "=", StringComparison.Ordinal))
                return line.Substring(key.Length + 1).Trim().Trim('"');
        }
        return string.Empty;
    }

    private static Dictionary<string, int> ReadPids(string path)
    {
        var result = new Dictionary<string, int>(StringComparer.OrdinalIgnoreCase);
        if (!File.Exists(path)) return result;
        foreach (string line in File.ReadAllLines(path))
        {
            string[] parts = line.Split('=');
            int pid;
            if (parts.Length == 2 && int.TryParse(parts[1], out pid)) result[parts[0]] = pid;
        }
        return result;
    }

    private static int GetLivePid(Dictionary<string, int> pids, string name)
    {
        int pid;
        if (!pids.TryGetValue(name, out pid)) return 0;
        try { return Process.GetProcessById(pid).HasExited ? 0 : pid; }
        catch { return 0; }
    }

    private static void WritePids(string path, int app, int queue, int statusQueue, int worker)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(path));
        File.WriteAllLines(path, new[] { "app=" + app, "queue=" + queue, "status-queue=" + statusQueue, "worker=" + worker });
    }

    private static void NormalizePathEnvironment()
    {
        string path = Environment.GetEnvironmentVariable("Path", EnvironmentVariableTarget.Process);
        Environment.SetEnvironmentVariable("Path", null, EnvironmentVariableTarget.Process);
        Environment.SetEnvironmentVariable("PATH", null, EnvironmentVariableTarget.Process);
        if (!string.IsNullOrWhiteSpace(path))
            Environment.SetEnvironmentVariable("Path", path, EnvironmentVariableTarget.Process);
    }

    private static bool IsPortOpen(int port)
    {
        try
        {
            using (var client = new TcpClient())
            {
                IAsyncResult result = client.BeginConnect("127.0.0.1", port, null, null);
                bool connected = result.AsyncWaitHandle.WaitOne(TimeSpan.FromMilliseconds(250));
                if (!connected) return false;
                client.EndConnect(result);
                return true;
            }
        }
        catch
        {
            return false;
        }
    }
}
