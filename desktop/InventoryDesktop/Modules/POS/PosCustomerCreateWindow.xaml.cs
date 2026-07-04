using System.Windows;

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
        NameBox.Focus();
    }

    public PosCustomerOption? CreatedCustomer { get; private set; }

    private async void Create_Click(object sender, RoutedEventArgs e)
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
