using System.Windows;
using System.Windows.Controls;
using MaterialDesignThemes.Wpf;

namespace InventoryDesktop.Modules.Admin;

public partial class SaasMasterView : UserControl
{
    private SaasMasterViewModel ViewModel => DataContext as SaasMasterViewModel;
    private bool platformAdminsLoaded;

    public SaasMasterView()
    {
        InitializeComponent();
        Loaded += async (_, _) =>
        {
            if (ViewModel is not null)
            {
                await ViewModel.LoadGroupsAsync();
            }
        };
    }

    public event EventHandler? CloseRequested;

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        CloseRequested?.Invoke(this, EventArgs.Empty);
    }

    private async void NewGroup_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null)
        {
            return;
        }

        var dialog = new CreateGroupDialog { Owner = Window.GetWindow(this) };
        if (dialog.ShowDialog() == true)
        {
            bool ok = await ViewModel.CreateGroupAsync(dialog.BuildRequest());
            if (ok)
            {
                DialogHost.CloseDialogCommand.Execute(null, this);
            }
        }
    }

    private async void NewSpinoff_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null || ViewModel.SelectedGroup is null)
        {
            return;
        }

        string groupSlug = ViewModel.SelectedGroup.Slug;
        var dialog = new CreateSpinoffDialog(groupSlug) { Owner = Window.GetWindow(this) };
        if (dialog.ShowDialog() == true)
        {
            bool ok = await ViewModel.CreateSpinoffAsync(groupSlug, dialog.BuildRequest());
            if (ok)
            {
                DialogHost.CloseDialogCommand.Execute(null, this);
            }
        }
    }

    private async void NewPlatformAdmin_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null)
        {
            return;
        }

        var dialog = new CreatePlatformAdminDialog { Owner = Window.GetWindow(this) };
        if (dialog.ShowDialog() == true)
        {
            (bool ok, string? initialPassword) = await ViewModel.CreatePlatformAdminAsync(dialog.BuildRequest());
            if (ok && initialPassword is { Length: > 0 })
            {
                MessageBox.Show(
                    Window.GetWindow(this),
                    $"Platform admin creado.\n\nUsuario: {dialog.BuildRequest().Email}\nContrasena inicial: {initialPassword}\n\nGuardala y compartila por un canal seguro. No se mostrara de nuevo.",
                    "Platform Admin creado",
                    MessageBoxButton.OK,
                    MessageBoxImage.Information);
            }
        }
    }

    private async void TabControl_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (ViewModel is null || platformAdminsLoaded)
        {
            return;
        }

        if (sender is TabControl tabControl && tabControl.SelectedIndex == 1)
        {
            platformAdminsLoaded = true;
            await ViewModel.LoadPlatformAdminsAsync();
        }
    }
}