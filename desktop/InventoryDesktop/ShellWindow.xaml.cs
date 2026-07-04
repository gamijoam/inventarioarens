using System.Windows;
using System.Windows.Controls;
using InventoryDesktop.Core.Diagnostics;
using InventoryDesktop.Core.Security;

namespace InventoryDesktop;

public partial class ShellWindow : Window
{
    public ShellWindow()
    {
        InitializeComponent();
        ShellContent.Content = BuildStatusView("Cargando panel principal...");
        AppLogger.Info("ShellWindow inicializada con vista de carga.");
    }

    public void LoadSession(DesktopSession session)
    {
        try
        {
            ShellContent.Content = new ShellView(session);
        }
        catch (Exception exception)
        {
            AppLogger.Error("Error cargando ShellView.", exception);
            ShellContent.Content = BuildStatusView(
                $"No se pudo cargar el panel principal.{Environment.NewLine}{exception.Message}{Environment.NewLine}{Environment.NewLine}Log: {AppLogger.LogPath}",
                isError: true);
        }
    }

    private static FrameworkElement BuildStatusView(string message, bool isError = false)
    {
        return new Grid
        {
            Background = System.Windows.Media.Brushes.White,
            Children =
            {
                new Border
                {
                    Width = 520,
                    Padding = new Thickness(28),
                    CornerRadius = new CornerRadius(14),
                    BorderThickness = new Thickness(1),
                    BorderBrush = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(220, 228, 242)),
                    Background = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(247, 249, 253)),
                    HorizontalAlignment = HorizontalAlignment.Center,
                    VerticalAlignment = VerticalAlignment.Center,
                    Child = new StackPanel
                    {
                        Children =
                        {
                            new TextBlock
                            {
                                Text = isError ? "Error al abrir el panel" : "Sistema de Inventario",
                                FontSize = 24,
                                FontWeight = FontWeights.Black,
                                Margin = new Thickness(0, 0, 0, 12)
                            },
                            new TextBlock
                            {
                                Text = message,
                                TextWrapping = TextWrapping.Wrap,
                                FontSize = 15,
                                Foreground = new System.Windows.Media.SolidColorBrush(
                                    isError
                                        ? System.Windows.Media.Color.FromRgb(217, 54, 92)
                                        : System.Windows.Media.Color.FromRgb(100, 113, 140))
                            }
                        }
                    }
                }
            }
        };
    }
}
