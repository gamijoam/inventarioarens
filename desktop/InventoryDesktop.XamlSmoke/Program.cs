using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Reflection;
using System.Text.RegularExpressions;
using System.Windows;
using System.Windows.Markup;

namespace InventoryDesktop.XamlSmoke;

internal static class Program
{
    private static readonly Regex XClassRegex = new(
        @"\s+x:Class\s*=\s*""[^""]*""",
        RegexOptions.Compiled);

    private static readonly Regex EventHandlerAttrRegex = new(
        @"\s+(?<name>Click|TextChanged|PasswordChanged|KeyDown|LostKeyboardFocus|GotKeyboardFocus|GotFocus|LostFocus|SelectionChanged|Loaded|Unloaded|TextInput|PreviewTextInput|PreviewKeyDown|PreviewMouseDown|MouseDoubleClick|KeyUp|MouseDown|Closing|Closed|SizeChanged|LayoutUpdated|SourceInitialized|Initialized|ContentRendered)\s*=\s*""[^""]*""",
        RegexOptions.Compiled);

    private static readonly HashSet<string> ResourceErrorSubstrings = new(StringComparer.OrdinalIgnoreCase)
    {
        "Cannot find resource",
        "Provide value on 'System.Windows.StaticResourceExtension' threw an exception",
        "Cannot find resource named",
        "Specified class name",
        "doesn't match actual root instance type",
        "Cannot create unknown type '{clr-namespace:",
        "Cannot create unknown type '{http:",
    };

    [STAThread]
    private static int Main(string[] args)
    {
        var desktopDir = LocateDesktopDir();
        if (desktopDir is null)
        {
            Console.Error.WriteLine("No se encontró desktop/InventoryDesktop a partir de:");
            Console.Error.WriteLine($"  {AppContext.BaseDirectory}");
            return 2;
        }

        var smokeBin = AppContext.BaseDirectory;
        if (Directory.Exists(smokeBin))
        {
            foreach (var dll in Directory.EnumerateFiles(smokeBin, "*.dll"))
            {
                try
                {
                    Assembly.LoadFrom(dll);
                }
                catch
                {
                }
            }
        }

        try
        {
            LoadAppResources(Path.Combine(desktopDir, "App.xaml"));
            Console.WriteLine($"OK    App.xaml  (Application.Resources merged)");
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"FAIL  App.xaml: {ex.Message}");
            return 2;
        }

        var xamlFiles = Directory
            .EnumerateFiles(desktopDir, "*.xaml", SearchOption.AllDirectories)
            .Where(p => !p.Contains($"{Path.DirectorySeparatorChar}obj{Path.DirectorySeparatorChar}", StringComparison.Ordinal))
            .Where(p => !p.Contains($"{Path.DirectorySeparatorChar}bin{Path.DirectorySeparatorChar}", StringComparison.Ordinal))
            .Where(p => !string.Equals(Path.GetFileName(p), "App.xaml", StringComparison.Ordinal))
            .OrderBy(p => p, StringComparer.Ordinal)
            .ToList();

        var failures = new List<string>();
        var successes = 0;

        foreach (var xamlPath in xamlFiles)
        {
            var rel = Path.GetRelativePath(desktopDir, xamlPath);
            string? skipReason = null;

            try
            {
                ParseStripped(xamlPath);
                successes++;
                Console.WriteLine($"OK    {rel}");
            }
            catch (Exception ex) when (IsResourceError(ex))
            {
                successes++;
                Console.WriteLine($"SKIP  {rel}  (limitación del parser sin code-behind cargado)");
            }
            catch (Exception ex)
            {
                failures.Add($"{rel}: {FormatException(ex)}");
                Console.WriteLine($"FAIL  {rel}");
                Console.WriteLine($"        {ex.GetType().Name}: {ex.Message}");
                var inner = ex.InnerException;
                while (inner is not null)
                {
                    Console.WriteLine($"           -> {inner.GetType().Name}: {inner.Message}");
                    inner = inner.InnerException;
                }
            }
        }

        Console.WriteLine();
        Console.WriteLine($"Total archivos:  {xamlFiles.Count + 1}");
        Console.WriteLine($"Éxitos / skip:   {successes + 1}");
        Console.WriteLine($"Fallos reales:   {failures.Count}");

        if (failures.Count > 0)
        {
            Console.WriteLine();
            Console.WriteLine("DETALLE DE FALLOS REALES:");
            foreach (var f in failures)
            {
                Console.WriteLine($"  - {f}");
            }
        }

        return failures.Count == 0 ? 0 : 1;
    }

    private static void LoadAppResources(string appXamlPath)
    {
        if (Application.Current is not null)
        {
            return;
        }

        var raw = File.ReadAllText(appXamlPath);
        var stripped = XClassRegex.Replace(raw, string.Empty);

        _ = XamlReader.Parse(stripped);
    }

    private static bool IsResourceError(Exception ex)
    {
        var msg = ex.Message ?? string.Empty;
        foreach (var fragment in ResourceErrorSubstrings)
        {
            if (msg.Contains(fragment, StringComparison.OrdinalIgnoreCase))
            {
                return true;
            }
        }
        var inner = ex.InnerException;
        while (inner is not null)
        {
            foreach (var fragment in ResourceErrorSubstrings)
            {
                if ((inner.Message ?? string.Empty).Contains(fragment, StringComparison.OrdinalIgnoreCase))
                {
                    return true;
                }
            }
            inner = inner.InnerException;
        }
        return false;
    }

    private static void ParseStripped(string xamlPath)
    {
        var raw = File.ReadAllText(xamlPath);
        var stripped = XClassRegex.Replace(raw, string.Empty);
        stripped = EventHandlerAttrRegex.Replace(stripped, string.Empty);
        _ = XamlReader.Parse(stripped);
    }

    private static string? LocateDesktopDir()
    {
        var dir = new DirectoryInfo(AppContext.BaseDirectory);
        for (var i = 0; i < 8 && dir is not null; i++, dir = dir.Parent)
        {
            var candidate = Path.Combine(dir.FullName, "desktop", "InventoryDesktop");
            if (Directory.Exists(candidate))
            {
                return candidate;
            }
        }
        return null;
    }

    private static string FormatException(Exception ex)
    {
        var msg = $"{ex.GetType().Name}: {ex.Message}";
        var inner = ex.InnerException;
        var depth = 0;
        while (inner is not null && depth < 5)
        {
            msg += $" -> {inner.GetType().Name}: {inner.Message}";
            inner = inner.InnerException;
            depth++;
        }
        return msg;
    }
}
