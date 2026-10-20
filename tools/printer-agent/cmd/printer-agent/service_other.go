//go:build !windows

package main

import "errors"

func isWindowsService() bool {
	return false
}

func runService(port int, bind string) error {
	return errors.New("servicio Windows solo soportado en Windows")
}

func installService(port int, bind string) error {
	return errors.New("instalacion de servicio solo soportada en Windows")
}

func uninstallService() error {
	return errors.New("desinstalacion de servicio solo soportada en Windows")
}
