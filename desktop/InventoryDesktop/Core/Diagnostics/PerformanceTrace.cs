using System.Diagnostics;

namespace InventoryDesktop.Core.Diagnostics;

public sealed class PerformanceTrace : IDisposable
{
    private readonly Stopwatch stopwatch = Stopwatch.StartNew();
    private readonly string operation;
    private readonly int warnAfterMilliseconds;
    private bool disposed;

    private PerformanceTrace(string operation, int warnAfterMilliseconds)
    {
        this.operation = operation;
        this.warnAfterMilliseconds = warnAfterMilliseconds;
    }

    public static PerformanceTrace Start(string operation, int warnAfterMilliseconds = 300)
    {
        return new PerformanceTrace(operation, warnAfterMilliseconds);
    }

    public void Dispose()
    {
        if (disposed)
        {
            return;
        }

        disposed = true;
        stopwatch.Stop();
        string level = stopwatch.ElapsedMilliseconds >= warnAfterMilliseconds ? "LENTO" : "OK";
        AppLogger.Info($"PERF {level} {operation}: {stopwatch.ElapsedMilliseconds} ms");
    }
}
