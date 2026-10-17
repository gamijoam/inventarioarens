package main

import "testing"

func TestMergeEnvironmentReplacesAndDeduplicatesWindowsKeys(t *testing.T) {
	got := mergeEnvironment(
		[]string{"Path=C:\\Windows", "DB_DATABASE=old.sqlite", "APP_ENV=local"},
		"PATH=C:\\BalanzaPro",
		"DB_DATABASE=C:\\ProgramData\\BalanzaPro\\database.sqlite",
	)
	values := map[string]string{}
	counts := map[string]int{}
	for _, entry := range got {
		var key, value string
		for i, r := range entry {
			if r == '=' {
				key, value = entry[:i], entry[i+1:]
				break
			}
		}
		values[key] = value
		counts[key]++
	}
	if values["PATH"] != "C:\\BalanzaPro" {
		t.Fatalf("PATH override missing: %#v", got)
	}
	if values["DB_DATABASE"] != "C:\\ProgramData\\BalanzaPro\\database.sqlite" {
		t.Fatalf("DB_DATABASE override missing: %#v", got)
	}
	if counts["PATH"] != 1 || counts["DB_DATABASE"] != 1 {
		t.Fatalf("duplicate environment keys: %#v", got)
	}
}
