' Run a command line completely hidden (no black console window).
'
' Usage:
'   wscript.exe run-sync-hidden.vbs "<absolute-path-to-launcher>" [extra args...]
'
' Why this exists:
'   Windows Scheduled Tasks run their /TR action in a visible console by
'   default. Even with -WindowStyle Hidden on Start-Process inside the
'   launcher, the parent cmd.exe / powershell.exe windows flash for a
'   few hundred milliseconds every time the task fires. This wrapper
'   asks the Windows shell to start the launcher with intWindowStyle=0
'   (vbHide), so nothing pops up at all.
'
' Notes:
'   - The launcher (and any process it spawns) inherits the hidden
'     window style only for the very first console. Long-running
'     daemons that have already detached (e.g. the sync worker after
'     it calls Start-Process -WindowStyle Hidden) keep running fine.
'   - The exit code is intentionally NOT propagated (bWaitOnReturn=False)
'     because the launcher exits almost immediately and the worker
'     is a fire-and-forget daemon. Logs go to the launcher's own log
'     file as configured in sync-worker.ps1.
Option Explicit

Dim shell
Dim commandLine
Dim i

Set shell = CreateObject("Wscript.Shell")

If WScript.Arguments.Count = 0 Then
    WScript.Echo "run-sync-hidden.vbs: missing launcher path."
    WScript.Quit 2
End If

commandLine = """" & WScript.Arguments(0) & """"
For i = 1 To WScript.Arguments.Count - 1
    commandLine = commandLine & " """ & Replace(WScript.Arguments(i), """", """""") & """"
Next

shell.Run commandLine, 0, False
Set shell = Nothing
