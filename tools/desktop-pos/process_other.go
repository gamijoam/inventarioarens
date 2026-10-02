//go:build !windows

package main

import "os/exec"

func setSilentProcess(cmd *exec.Cmd) {}
