using System.Windows;
using System.Windows.Input;

namespace InventoryDesktop.Modules.POS;

public partial class PosCustomerCreateWindow : Window
{
    private readonly PosViewModel viewModel;

    public PosCustomerCreateWindow(PosViewModel viewModel)
    {
        InitializeComponent();
        this.viewModel = viewModel;
        DocumentTypeBox.ItemsSource = new[] { "V", "E", "J", "G", "P" };
        DocumentTypeBox.SelectedIndex = 0;
        PreviewKeyDown += CustomerCreateWindow_PreviewKeyDown;
        NameBox.Focus();
    }

    public PosCustomerOption? CreatedCustomer { get; private set; }

    private async void Create_Click(object sender, RoutedEventArgs e)
    {
        await CreateCustomerAsync();
    }

    private async Task CreateCustomerAsync()
    {
        StatusText.Text = string.Empty;
        string name = NameBox.Text.Trim();
        string documentType = DocumentTypeBox.SelectedItem as string ?? "V";
        string documentNumber = DocumentNumberBox.Text.Trim();

        if (string.IsNullOrWhiteSpace(name))
        {
            StatusText.Text = "Ingresa el nombre o razón social.";
            NameBox.Focus();
            return;
        }

        if (string.IsNullOrWhiteSpace(documentNumber))
        {
            StatusText.Text = "Ingresa el número de documento.";
            DocumentNumberBox.Focus();
            return;
        }

        try
        {
            IsEnabled = false;
            CreatedCustomer = await viewModel.CreateCustomerAsync(new PosCustomerCreateRequest(
                name,
                documentType,
                documentNumber,
                NullIfWhiteSpace(PhoneBox.Text),
                NullIfWhiteSpace(EmailBox.Text),
                NullIfWhiteSpace(FiscalAddressBox.Text),
                false,
                true));
            DialogResult = true;
            Close();
        }
        catch (InvalidOperationException exception)
        {
            IsEnabled = true;
            StatusText.Text = exception.Message;
        }
    }

    private async void CustomerCreateWindow_PreviewKeyDown(object sender, System.Windows.Input.KeyEventArgs e)
    {
        if (e.Key == System.Windows.Input.Key.Escape)
        {
            DialogResult = false;
            Close();
            e.Handled = true;
            return;
        }

        if (e.Key == System.Windows.Input.Key.Enter && Keyboard.FocusedElement != FiscalAddressBox)
        {
            await CreateCustomerAsync();
            e.Handled = true;
        }
    }

    private void Cancel_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
        Close();
    }

    private static string? NullIfWhiteSpace(string value)
    {
        string trimmed = value.Trim();
        return string.IsNullOrWhiteSpace(trimmed) ? null : trimmed;
    }
}
