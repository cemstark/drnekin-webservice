Set WshShell = CreateObject("WScript.Shell")
WshShell.CurrentDirectory = CreateObject("Scripting.FileSystemObject").GetParentFolderName(WScript.ScriptFullName)
' 0 = hidden window, True = wait until finished
WshShell.Run "cmd /c QR-URET.bat", 0, True
