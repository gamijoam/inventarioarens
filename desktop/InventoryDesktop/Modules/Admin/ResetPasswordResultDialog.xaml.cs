using System.Windows;

namespace InventoryDesktop.Modules.Admin;

public partial class ResetPasswordResultDialog : Window
{
    public ResetPasswordResultDialog(string email, string initialPassword)
    {
        InitializeComponent();
        EmailValue.Text = email;
        PasswordValue.Text = initialPassword;
    }

    private void Accept_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = true;
        Close();
    }
}