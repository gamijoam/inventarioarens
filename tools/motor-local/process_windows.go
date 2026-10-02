//go:build windows

package main

import (
	"os/exec"
	"syscall"
)

// setSilentProcess ensures child processes run invisibly without opening console windows on Windows.
func setSilentProcess(cmd *exec.Cmd) {
	if cmd.SysProcAttr == nil {
		cmd.SysProcAttr = &syscall.SysProcAttr{}
	}
	cmd.SysProcAttr.HideWindow = true
	cmd.SysProcAttr.CreationFlags |= 0x08000000 // CREATE_NO_WINDOW
}
