using System.Windows;
using System.Windows.Media;
using InventoryDesktop.Core.ViewModels;

namespace InventoryDesktop.Modules.Customers;

public partial class CustomerEditWindow : Window
{
    private readonly CustomersViewModel customersViewModel;
    private readonly CustomerItem? customer;
    private readonly CustomerEditViewModel formViewModel;

    public CustomerEditWindow(CustomersViewModel customersViewModel, CustomerItem? customer = null)
    {
        InitializeComponent();
        this.customersViewModel = customersViewModel;
        this.customer = customer;
        formViewModel = new CustomerEditViewModel(customer);
        DataContext = formViewModel;
        Loaded += (_, _) => NameTextBox.Focus();
    }

    public CustomerItem? SavedCustomer { get; private set; }

    private void Cancel_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
    }

    private async void Save_Click(object sender, RoutedEventArgs e)
    {
        if (!formViewModel.TryBuildRequest(out CustomerSaveRequest? request))
        {
            return;
        }

        SavedCustomer = await customersViewModel.SaveAsync(request!, customer?.Id);
        if (SavedCustomer is not null)
        {
            DialogResult = true;
        }
        else
        {
            formViewModel.StatusMessage = customersViewModel.StatusMessage;
            formViewModel.IsStatusError = true;
        }
    }
}

public sealed class CustomerEditViewModel : ViewModelBase
{
    private string nameInput = string.Empty;
    private string documentTypeInput = "V";
    private string documentNumberInput = string.Empty;
    private string? phoneInput;
    private string? emailInput;
    private string? fiscalAddressInput;
    private bool isGenericInput;
    private bool isActiveInput = true;
    private bool isStatusError;
    private string statusMessage = "Completa los datos y guarda el cliente.";

    public CustomerEditViewModel(CustomerItem? customer)
    {
        if (customer is not null)
        {
            nameInput = customer.Name;
            documentTypeInput = customer.DocumentType;
            documentNumberInput = customer.DocumentNumber;
            phoneInput = customer.Phone;
            emailInput = customer.Email;
            fiscalAddressInput = customer.FiscalAddress;
            isGenericInput = customer.IsGeneric;
            isActiveInput = customer.IsActive;
        }
    }

    public IReadOnlyList<string> DocumentTypes { get; } = ["V", "E", "J", "G", "P"];

    public string TitleText => string.IsNullOrWhiteSpace(NameInput) ? "Nuevo cliente" : NameInput;

    public string NameInput
    {
        get => nameInput;
        set
        {
            if (SetProperty(ref nameInput, value))
            {
                RaisePropertyChanged(nameof(TitleText));
            }
        }
    }

    public string DocumentTypeInput
    {
        get => documentTypeInput;
        set => SetProperty(ref documentTypeInput, value);
    }

    public string DocumentNumberInput
    {
        get => documentNumberInput;
        set => SetProperty(ref documentNumberInput, value);
    }

    public string? PhoneInput
    {
        get => phoneInput;
        set => SetProperty(ref phoneInput, value);
    }

    public string? EmailInput
    {
        get => emailInput;
        set => SetProperty(ref emailInput, value);
    }

    public string? FiscalAddressInput
    {
        get => fiscalAddressInput;
        set => SetProperty(ref fiscalAddressInput, value);
    }

    public bool IsGenericInput
    {
        get => isGenericInput;
        set => SetProperty(ref isGenericInput, value);
    }

    public bool IsActiveInput
    {
        get => isActiveInput;
        set => SetProperty(ref isActiveInput, value);
    }

    public bool IsStatusError
    {
        get => isStatusError;
        set
        {
            if (SetProperty(ref isStatusError, value))
            {
                RaisePropertyChanged(nameof(StatusBrush));
            }
        }
    }

    public string StatusMessage
    {
        get => statusMessage;
        set => SetProperty(ref statusMessage, value);
    }

    public Brush StatusBrush => IsStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public bool TryBuildRequest(out CustomerSaveRequest? request)
    {
        request = null;
        string name = NameInput.Trim();
        string document = DocumentNumberInput.Trim();

        if (string.IsNullOrWhiteSpace(name))
        {
            SetError("El nombre del cliente es obligatorio.");
            return false;
        }

        if (string.IsNullOrWhiteSpace(document))
        {
            SetError("El documento del cliente es obligatorio.");
            return false;
        }

        request = new CustomerSaveRequest(
            name,
            DocumentTypeInput,
            document,
            NormalizeOptional(PhoneInput),
            NormalizeOptional(EmailInput),
            NormalizeOptional(FiscalAddressInput),
            IsGenericInput,
            IsActiveInput);
        return true;
    }

    private void SetError(string message)
    {
        IsStatusError = true;
        StatusMessage = message;
    }

    private static string? NormalizeOptional(string? value)
    {
        string? normalized = value?.Trim();
        return string.IsNullOrWhiteSpace(normalized) ? null : normalized;
    }
}
