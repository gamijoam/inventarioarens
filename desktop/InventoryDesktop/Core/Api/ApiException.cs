using System.Net;

namespace InventoryDesktop.Core.Api;

public sealed class ApiException : Exception
{
    public ApiException(string message, HttpStatusCode statusCode, string responseBody)
        : base(message)
    {
        StatusCode = statusCode;
        ResponseBody = responseBody;
    }

    public HttpStatusCode StatusCode { get; }

    public string ResponseBody { get; }
}
