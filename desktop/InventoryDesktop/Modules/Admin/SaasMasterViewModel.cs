using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Runtime.CompilerServices;
using System.Windows;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.Diagnostics;
using InventoryDesktop.Core.ViewModels;
using MaterialDesignThemes.Wpf;

namespace InventoryDesktop.Modules.Admin;

public sealed class SaasMasterViewModel : ViewModelBase
{
    private readonly ApiClient apiClient;
    private string statusMessage = "";
    private bool isStatusError;
    private bool isBusy;
    private GroupResource? selectedGroup;
    private SpinoffResource? selectedSpinoff;

    public SaasMasterViewModel(ApiClient apiClient)
    {
        this.apiClient = apiClient;
        Groups = new ObservableCollection<GroupResource>();
        Spinoffs = new ObservableCollection<SpinoffResource>();
    }

    public ObservableCollection<GroupResource> Groups { get; }
    public ObservableCollection<SpinoffResource> Spinoffs { get; }
    public ObservableCollection<PlatformAdminResource> PlatformAdmins { get; } = new();

    public string StatusMessage
    {
        get => statusMessage;
        private set { if (SetProperty(ref statusMessage, value)) { } }
    }

    public bool IsStatusError
    {
        get => isStatusError;
        private set { if (SetProperty(ref isStatusError, value)) { } }
    }

    public bool IsBusy
    {
        get => isBusy;
        private set { if (SetProperty(ref isBusy, value)) { } }
    }

    public GroupResource? SelectedGroup
    {
        get => selectedGroup;
        set
        {
            if (SetProperty(ref selectedGroup, value))
            {
                RaisePropertyChanged(nameof(HasSelectedGroup));
                _ = LoadSpinoffsAsync(value?.Slug);
            }
        }
    }

    public bool HasSelectedGroup => SelectedGroup is not null;

    public SpinoffResource? SelectedSpinoff
    {
        get => selectedSpinoff;
        set => SetProperty(ref selectedSpinoff, value);
    }

    public async Task LoadGroupsAsync()
    {
        IsBusy = true;
        IsStatusError = false;
        StatusMessage = "Cargando grupos...";

        try
        {
            GroupListResponse response = await apiClient.GetAsync<GroupListResponse>("master/groups");
            Groups.Clear();
            foreach (GroupResource group in response.Data)
            {
                Groups.Add(group);
            }
            StatusMessage = Groups.Count == 0
                ? "No hay grupos creados. Usa 'Nuevo Grupo' para crear el primero."
                : $"{Groups.Count} grupo(s) cargados.";
        }
        catch (ApiException exception)
        {
            IsStatusError = true;
            StatusMessage = $"No se pudieron cargar los grupos: {exception.Message}";
            AppLogger.Error("LoadGroupsAsync falló.", exception);
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task LoadSpinoffsAsync(string? groupSlug)
    {
        if (string.IsNullOrWhiteSpace(groupSlug))
        {
            Spinoffs.Clear();
            return;
        }

        IsBusy = true;
        IsStatusError = false;
        StatusMessage = $"Cargando spinoffs de '{groupSlug}'...";

        try
        {
            SpinoffListResponse response = await apiClient.GetAsync<SpinoffListResponse>($"groups/{groupSlug}/tenants");
            Spinoffs.Clear();
            foreach (SpinoffResource spinoff in response.Data)
            {
                Spinoffs.Add(spinoff);
            }
            StatusMessage = $"{Spinoffs.Count} spinoff(s) encontrado(s).";
        }
        catch (ApiException exception)
        {
            IsStatusError = true;
            StatusMessage = $"No se pudieron cargar los spinoffs: {exception.Message}";
            AppLogger.Error($"LoadSpinoffsAsync falló para '{groupSlug}'.", exception);
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task<bool> CreateGroupAsync(CreateGroupRequest request)
    {
        if (request is null)
        {
            return false;
        }

        IsBusy = true;
        IsStatusError = false;
        StatusMessage = $"Creando grupo '{request.Name}'...";

        try
        {
            SingleGroupResponse response = await apiClient.PostAsync<CreateGroupRequest, SingleGroupResponse>("master/groups", request);
            Groups.Insert(0, response.Data);
            SelectedGroup = response.Data;
            StatusMessage = $"Grupo '{response.Data.Name}' creado.";
            return true;
        }
        catch (ApiException exception)
        {
            IsStatusError = true;
            StatusMessage = $"No se pudo crear el grupo: {exception.Message}";
            AppLogger.Error("CreateGroupAsync falló.", exception);
            return false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task<bool> CreateSpinoffAsync(string groupSlug, CreateSpinoffRequest request)
    {
        if (string.IsNullOrWhiteSpace(groupSlug) || request is null)
        {
            return false;
        }

        IsBusy = true;
        IsStatusError = false;
        StatusMessage = $"Creando spinoff '{request.Name}'...";

        try
        {
            SingleSpinoffResponse response = await apiClient.PostAsync<CreateSpinoffRequest, SingleSpinoffResponse>($"groups/{groupSlug}/tenants", request);
            Spinoffs.Insert(0, response.Data);
            SelectedSpinoff = response.Data;
            StatusMessage = $"Spinoff '{response.Data.Name}' creado en '{groupSlug}'.";
            return true;
        }
        catch (ApiException exception)
        {
            IsStatusError = true;
            StatusMessage = $"No se pudo crear el spinoff: {exception.Message}";
            AppLogger.Error("CreateSpinoffAsync falló.", exception);
            return false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task LoadPlatformAdminsAsync()
    {
        IsBusy = true;
        IsStatusError = false;
        StatusMessage = "Cargando platform admins...";

        try
        {
            IReadOnlyList<PlatformAdminResource> response = await apiClient.GetAsync<IReadOnlyList<PlatformAdminResource>>("master/admins");
            PlatformAdmins.Clear();
            foreach (PlatformAdminResource admin in response)
            {
                PlatformAdmins.Add(admin);
            }
            StatusMessage = PlatformAdmins.Count == 0
                ? "No hay platform admins. Crea el primero con 'Nuevo Platform Admin'."
                : $"{PlatformAdmins.Count} platform admin(s) cargados.";
        }
        catch (ApiException exception)
        {
            IsStatusError = true;
            StatusMessage = $"No se pudieron cargar los platform admins: {exception.Message}";
            AppLogger.Error("LoadPlatformAdminsAsync falló.", exception);
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task<(bool ok, string? initialPassword)> CreatePlatformAdminAsync(CreatePlatformAdminRequest request)
    {
        if (request is null)
        {
            return (false, null);
        }

        IsBusy = true;
        IsStatusError = false;
        StatusMessage = $"Creando platform admin '{request.Email}'...";

        try
        {
            PlatformAdminStoreResponse response = await apiClient.PostAsync<CreatePlatformAdminRequest, PlatformAdminStoreResponse>("master/admins", request);
            if (!PlatformAdmins.Any(a => a.Id == response.Data.Id))
            {
                PlatformAdmins.Insert(0, response.Data);
            }
            StatusMessage = response.InitialPassword is { Length: > 0 }
                ? $"Platform admin '{response.Data.Email}' creado. Contraseña inicial: {response.InitialPassword}"
                : $"Platform admin '{response.Data.Email}' actualizado.";
            return (true, response.InitialPassword);
        }
        catch (ApiException exception)
        {
            IsStatusError = true;
            StatusMessage = $"No se pudo crear el platform admin: {exception.Message}";
            AppLogger.Error("CreatePlatformAdminAsync falló.", exception);
            return (false, null);
        }
        finally
        {
            IsBusy = false;
        }
    }
}
