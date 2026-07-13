using System.Windows;
using System.Windows.Controls;
using MaterialDesignThemes.Wpf;

namespace InventoryDesktop.Modules.Admin;

public partial class SaasMasterView : UserControl
{
    private SaasMasterViewModel ViewModel => DataContext as SaasMasterViewModel;
    private bool platformAdminsLoaded;
    private bool statsLoaded;

    public SaasMasterView()
    {
        InitializeComponent();
        Loaded += async (_, _) =>
        {
            if (ViewModel is not null)
            {
                await ViewModel.LoadGroupsAsync();
                await ViewModel.LoadStatsAsync();
                await ViewModel.LoadCurrentAdminAsync();
            }
        };
    }

    public event EventHandler? CloseRequested;

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        CloseRequested?.Invoke(this, EventArgs.Empty);
    }

    private async void Logout_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null)
        {
            return;
        }

        MessageBoxResult result = MessageBox.Show(
            Window.GetWindow(this),
            "Cerrar sesion de Platform Admin revocara tu token actual. Continuar?",
            "Cerrar sesion",
            MessageBoxButton.YesNo,
            MessageBoxImage.Question);

        if (result != MessageBoxResult.Yes)
        {
            return;
        }

        bool ok = await ViewModel.LogoutAsync();
        if (ok)
        {
            CloseRequested?.Invoke(this, EventArgs.Empty);
        }
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
                await ViewModel.LoadStatsAsync();
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
                await ViewModel.LoadStatsAsync();
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

    private async void GroupAction_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not Button { Tag: GroupResource group } || ViewModel is null)
        {
            return;
        }

        var menu = new ContextMenu();
        MenuItem editItem = new() { Header = "Editar grupo" };
        editItem.Click += async (_, _) => await ShowEditGroupDialog(group);
        menu.Items.Add(editItem);

        MenuItem deactivateItem = new() { Header = group.Status == "inactive" ? "Reactivar (re-cargar)" : "Desactivar (soft delete)" };
        deactivateItem.Click += async (_, _) =>
        {
            if (group.Status == "inactive")
            {
                await ViewModel.LoadGroupDetailAsync(group.Slug);
            }
            else
            {
                MessageBoxResult result = MessageBox.Show(
                    Window.GetWindow(this),
                    $"Desactivar el grupo '{group.Name}'?\n\nSus usuarios no podran autenticarse hasta reactivarlo.",
                    "Desactivar grupo",
                    MessageBoxButton.YesNo,
                    MessageBoxImage.Warning);
                if (result == MessageBoxResult.Yes)
                {
                    await ViewModel.SoftDeleteGroupAsync(group.Slug);
                    await ViewModel.LoadStatsAsync();
                }
            }
        };
        menu.Items.Add(deactivateItem);

        menu.PlacementTarget = sender as Button;
        menu.IsOpen = true;
    }

    private async System.Threading.Tasks.Task ShowEditGroupDialog(GroupResource group)
    {
        if (ViewModel is null)
        {
            return;
        }

        var dialog = new EditGroupDialog(group) { Owner = Window.GetWindow(this) };
        if (dialog.ShowDialog() == true)
        {
            bool ok = await ViewModel.UpdateGroupAsync(group.Slug, dialog.BuildRequest());
            if (ok)
            {
                DialogHost.CloseDialogCommand.Execute(null, this);
                await ViewModel.LoadStatsAsync();
            }
        }
    }

    private async void AdminAction_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not Button { Tag: PlatformAdminResource admin } || ViewModel is null)
        {
            return;
        }

        var menu = new ContextMenu();

        MenuItem editItem = new() { Header = "Editar nombre/correo/acceso" };
        editItem.Click += async (_, _) => await ShowEditAdminDialog(admin);
        menu.Items.Add(editItem);

        MenuItem resetItem = new() { Header = "Resetear contrasena" };
        resetItem.Click += async (_, _) =>
        {
            (bool ok, string? initialPassword) = await ViewModel.ResetAdminPasswordAsync(admin.Id, null);
            if (ok && initialPassword is { Length: > 0 })
            {
                var resultDialog = new ResetPasswordResultDialog(admin.Email, initialPassword)
                {
                    Owner = Window.GetWindow(this)
                };
                resultDialog.ShowDialog();
            }
        };
        menu.Items.Add(resetItem);

        MenuItem revokeItem = new() { Header = "Revocar acceso Platform Admin" };
        revokeItem.Click += async (_, _) =>
        {
            long currentId = ViewModel.CurrentAdmin?.Id ?? 0;
            if (admin.Id == currentId)
            {
                MessageBox.Show(
                    Window.GetWindow(this),
                    "No puedes revocarte a ti mismo. Pide a otro Platform Admin que lo haga.",
                    "Accion bloqueada",
                    MessageBoxButton.OK,
                    MessageBoxImage.Warning);
                return;
            }
            MessageBoxResult result = MessageBox.Show(
                Window.GetWindow(this),
                $"Revocar acceso Platform Admin de '{admin.Email}'?\n\nEsto cierra todas sus sesiones activas.",
                "Revocar admin",
                MessageBoxButton.YesNo,
                MessageBoxImage.Warning);
            if (result == MessageBoxResult.Yes)
            {
                await ViewModel.RevokeAdminAsync(admin.Id);
                await ViewModel.LoadStatsAsync();
            }
        };
        menu.Items.Add(revokeItem);

        menu.PlacementTarget = sender as Button;
        menu.IsOpen = true;
    }

    private async System.Threading.Tasks.Task ShowEditAdminDialog(PlatformAdminResource admin)
    {
        if (ViewModel is null)
        {
            return;
        }

        long currentId = ViewModel.CurrentAdmin?.Id ?? 0;
        var dialog = new EditPlatformAdminDialog(admin, currentId) { Owner = Window.GetWindow(this) };
        if (dialog.ShowDialog() == true)
        {
            (bool ok, _) = await ViewModel.UpdateAdminAsync(admin.Id, dialog.BuildRequest());
            if (ok)
            {
                DialogHost.CloseDialogCommand.Execute(null, this);
                await ViewModel.LoadCurrentAdminAsync();
            }
        }
    }

    private async void TabControl_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (ViewModel is null)
        {
            return;
        }

        if (sender is TabControl tabControl)
        {
            if (tabControl.SelectedIndex == 1 && !platformAdminsLoaded)
            {
                platformAdminsLoaded = true;
                await ViewModel.LoadPlatformAdminsAsync();
            }
            if (tabControl.SelectedIndex == 0 && !statsLoaded && ViewModel.Stats is null)
            {
                statsLoaded = true;
                await ViewModel.LoadStatsAsync();
            }
        }
    }
}