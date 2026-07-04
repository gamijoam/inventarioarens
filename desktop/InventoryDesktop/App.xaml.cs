using System.Windows;
using InventoryDesktop.Core.Diagnostics;

namespace InventoryDesktop;

public partial class App : Application
{
    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        AppLogger.Info("Aplicacion WPF iniciada.");
        DispatcherUnhandledException += (_, args) =>
        {
            AppLogger.Error("Excepcion no controlada en Dispatcher.", args.Exception);
            MessageBox.Show(
                $"Ocurrio un error inesperado y fue registrado en:{Environment.NewLine}{AppLogger.LogPath}{Environment.NewLine}{Environment.NewLine}{args.Exception.Message}",
                "Error de la aplicacion",
                MessageBoxButton.OK,
                MessageBoxImage.Error);
            args.Handled = true;
        };

        AppDomain.CurrentDomain.UnhandledException += (_, args) =>
        {
            if (args.ExceptionObject is Exception exception)
            {
                AppLogger.Error("Excepcion fatal de aplicacion.", exception);
            }
        };

        TaskScheduler.UnobservedTaskException += (_, args) =>
        {
            AppLogger.Error("Excepcion no observada en tarea.", args.Exception);
            args.SetObserved();
        };
    }
}
