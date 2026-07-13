using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Runtime.CompilerServices;
using System.Windows;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.Diagnostics;
using InventoryDesktop.Core.ViewModels;
using InventoryDesktop.Modules.Auth;
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
    private PlatformAdminResource? currentAdmin;
    private MasterStatsResponse? stats;

    public SaasMasterViewModel(ApiClient apiClient)
    {
        this.apiClient = apiClient;
        Groups = new ObservableCollection<GroupResource>();
        Spinoffs = new ObservableCollection<SpinoffResource>();
    }

    public ObservableCollection<GroupResource> Groups { get; }
    public ObservableCollection<SpinoffResource> Spinoffs { get; }
    public ObservableCollection<PlatformAdminResource> PlatformAdmins { get; } = new();

    public PlatformAdminResource? CurrentAdmin
    {
        get => currentAdmin;
        private set => SetProperty(ref currentAdmin, value);
    }

    public MasterStatsResponse? Stats
    {
        get => stats;
        private set
        {
            if (SetProperty(ref stats, value))
            {
                RaisePropertyChanged(nameof(HasStats));
            }
        }
    }

    public bool HasStats => Stats is not null;

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
            SpinoffListResponse response = await apiClient.GetAsync<SpinoffListResponse>($"master/groups/{groupSlug}/tenants");
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
            SingleSpinoffResponse response = await apiClient.PostAsync<CreateSpinoffRequest, SingleSpinoffResponse>($"master/groups/{groupSlug}/tenants", request);
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

    public async Task LoadStatsAsync()
    {
        IsBusy = true;
        IsStatusError = false;
        try
        {
            MasterStatsEnvelope envelope = await apiClient.GetAsync<MasterStatsEnvelope>("master/stats");
            Stats = envelope.Data;
            StatusMessage = $"Stats cargados: {Stats.Totals.TotalGroups} grupo(s), {Stats.Totals.TotalSpinoffs} spinoff(s).";
        }
        catch (ApiException exception)
        {
            IsStatusError = true;
            StatusMessage = $"No se pudieron cargar las stats: {exception.Message}";
            AppLogger.Error("LoadStatsAsync falló.", exception);
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task LoadCurrentAdminAsync()
    {
        try
        {
            CurrentSessionResponse response = await apiClient.GetAsync<CurrentSessionResponse>("auth/me");
            CurrentAdmin = new PlatformAdminResource(
                Id: response.Data.User.Id,
                Name: response.Data.User.Name,
                Email: response.Data.User.Email,
                IsPlatformAdmin: response.Data.User.IsPlatformAdmin,
                IsActive: true,
                AuthTokensCount: 0,
                LastLoginAt: null,
                CreatedAt: null,
                UpdatedAt: null);
        }
        catch (ApiException exception)
        {
            AppLogger.Warn($"LoadCurrentAdminAsync no critico: {exception.Message}");
        }
    }

    public async Task<GroupResource?> LoadGroupDetailAsync(string groupSlug)
    {
        if (string.IsNullOrWhiteSpace(groupSlug))
        {
            return null;
        }

        IsBusy = true;
        IsStatusError = false;
        try
        {
            SingleGroupResponse response = await apiClient.GetAsync<SingleGroupResponse>($"master/groups/{groupSlug}");
            int index = IndexOfGroup(response.Data.Id);
            if (index >= 0)
            {
                Groups[index] = response.Data;
            }
            SelectedGroup = response.Data;
            StatusMessage = $"Grupo '{response.Data.Name}' actualizado.";
            return response.Data;
        }
        catch (ApiException exception)
        {
            IsStatusError = true;
            StatusMessage = $"No se pudo cargar el detalle del grupo: {exception.Message}";
            AppLogger.Error($"LoadGroupDetailAsync({groupSlug}) falló.", exception);
            return null;
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task<bool> UpdateGroupAsync(string groupSlug, UpdateGroupRequest request)
    {
        if (string.IsNullOrWhiteSpace(groupSlug) || request is null)
        {
            return false;
        }

        IsBusy = true;
        IsStatusError = false;
        StatusMessage = $"Actualizando grupo '{groupSlug}'...";
        try
        {
            SingleGroupResponse response = await apiClient.PatchAsync<UpdateGroupRequest, SingleGroupResponse>($"master/groups/{groupSlug}", request);
            int index = IndexOfGroup(response.Data.Id);
            if (index >= 0)
            {
                Groups[index] = response.Data;
            }
            SelectedGroup = response.Data;
            StatusMessage = $"Grupo '{response.Data.Name}' actualizado.";
            return true;
        }
        catch (ApiException exception)
        {
            IsStatusError = true;
            StatusMessage = $"No se pudo actualizar el grupo: {exception.Message}";
            AppLogger.Error($"UpdateGroupAsync({groupSlug}) falló.", exception);
            return false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task<bool> SoftDeleteGroupAsync(string groupSlug)
    {
        if (string.IsNullOrWhiteSpace(groupSlug))
        {
            return false;
        }

        IsBusy = true;
        IsStatusError = false;
        StatusMessage = $"Desactivando grupo '{groupSlug}'...";
        try
        {
            await apiClient.DeleteAsync($"master/groups/{groupSlug}");
            GroupResource? updated = await LoadGroupDetailAsync(groupSlug);
            if (updated is not null && string.Equals(updated.Status, "inactive", StringComparison.OrdinalIgnoreCase))
            {
                StatusMessage = $"Grupo '{updated.Name}' desactivado.";
                return true;
            }
            return true;
        }
        catch (ApiException exception)
        {
            IsStatusError = true;
            StatusMessage = $"No se pudo desactivar el grupo: {exception.Message}";
            AppLogger.Error($"SoftDeleteGroupAsync({groupSlug}) falló.", exception);
            return false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task<(bool ok, string? initialPassword)> UpdateAdminAsync(long adminId, UpdatePlatformAdminRequest request)
    {
        if (request is null)
        {
            return (false, null);
        }

        IsBusy = true;
        IsStatusError = false;
        StatusMessage = $"Actualizando admin #{adminId}...";
        try
        {
            SinglePlatformAdminResponse response = await apiClient.PatchAsync<UpdatePlatformAdminRequest, SinglePlatformAdminResponse>($"master/admins/{adminId}", request);
            int index = IndexOfAdmin(response.Data.Id);
            if (index >= 0)
            {
                PlatformAdmins[index] = response.Data;
            }
            StatusMessage = $"Admin '{response.Data.Email}' actualizado.";
            return (true, null);
        }
        catch (ApiException exception)
        {
            IsStatusError = true;
            StatusMessage = $"No se pudo actualizar el admin: {exception.Message}";
            AppLogger.Error($"UpdateAdminAsync({adminId}) falló.", exception);
            return (false, null);
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task<(bool ok, string? initialPassword)> ResetAdminPasswordAsync(long adminId, string? password)
    {
        IsBusy = true;
        IsStatusError = false;
        StatusMessage = $"Reseteando contrasena del admin #{adminId}...";
        try
        {
            ResetPasswordResponse response = await apiClient.PostAsync<ResetPlatformAdminPasswordRequest, ResetPasswordResponse>(
                $"master/admins/{adminId}/reset-password",
                new ResetPlatformAdminPasswordRequest(password));
            StatusMessage = response.Data.InitialPassword is { Length: > 0 }
                ? $"Contrasena reseteada. Inicial: {response.Data.InitialPassword}"
                : "Contrasena reseteada.";
            return (true, response.Data.InitialPassword);
        }
        catch (ApiException exception)
        {
            IsStatusError = true;
            StatusMessage = $"No se pudo resetear la contrasena: {exception.Message}";
            AppLogger.Error($"ResetAdminPasswordAsync({adminId}) falló.", exception);
            return (false, null);
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task<bool> RevokeAdminAsync(long adminId)
    {
        IsBusy = true;
        IsStatusError = false;
        StatusMessage = $"Revocando admin #{adminId}...";
        try
        {
            await apiClient.DeleteAsync($"master/admins/{adminId}");
            PlatformAdminResource? stillThere = PlatformAdmins.FirstOrDefault(a => a.Id == adminId);
            if (stillThere is not null)
            {
                int idx = IndexOfAdmin(adminId);
                if (idx >= 0)
                {
                    PlatformAdmins[idx] = stillThere with { IsPlatformAdmin = false, IsActive = false };
                }
            }
            StatusMessage = $"Admin #{adminId} revocado.";
            return true;
        }
        catch (ApiException exception)
        {
            IsStatusError = true;
            StatusMessage = $"No se pudo revocar el admin: {exception.Message}";
            AppLogger.Error($"RevokeAdminAsync({adminId}) falló.", exception);
            return false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task<bool> LogoutAsync()
    {
        IsBusy = true;
        IsStatusError = false;
        StatusMessage = "Cerrando sesion de Platform Admin...";
        try
        {
            await apiClient.PostNoPayloadAsync<LogoutResponse>("auth/logout");
            StatusMessage = "Sesion cerrada.";
            return true;
        }
        catch (ApiException exception)
        {
            IsStatusError = true;
            StatusMessage = $"Logout fallo: {exception.Message}";
            AppLogger.Error("LogoutAsync falló.", exception);
            return false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    private int IndexOfGroup(long id)
    {
        for (int i = 0; i < Groups.Count; i++)
        {
            if (Groups[i].Id == id)
            {
                return i;
            }
        }
        return -1;
    }

    private int IndexOfAdmin(long id)
    {
        for (int i = 0; i < PlatformAdmins.Count; i++)
        {
            if (PlatformAdmins[i].Id == id)
            {
                return i;
            }
        }
        return -1;
    }
}
