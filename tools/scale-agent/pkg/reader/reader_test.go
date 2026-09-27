package reader

import (
	"bytes"
	"context"
	"io"
	"testing"
	"time"

	"balanzapro/scale-agent/pkg/protocol"
)

func TestStreamReader_UpdatesLatestReading(t *testing.T) {
	mockData := "ST,GS,+  1.500kg\r\nST,GS,+  2.350kg\r\n"
	r := io.NopCloser(bytes.NewBufferString(mockData))

	parser := &protocol.GenericParser{}
	sr := NewStreamReader(r, parser)

	ctx, cancel := context.WithTimeout(context.Background(), 500*time.Millisecond)
	defer cancel()

	go sr.Start(ctx)

	// Wait for stream to be processed
	time.Sleep(50 * time.Millisecond)

	latest := sr.GetLatest()
	if latest == nil {
		t.Fatal("Expected latest reading, got nil")
	}

	if latest.Weight != 2.350 {
		t.Errorf("Expected latest weight 2.350, got %v", latest.Weight)
	}
	if latest.Unit != "kg" {
		t.Errorf("Expected unit 'kg', got %s", latest.Unit)
	}
}

func TestMockReader_GeneratesReadings(t *testing.T) {
	mr := NewMockReader(1.250, "kg", 10*time.Millisecond)

	ctx, cancel := context.WithTimeout(context.Background(), 100*time.Millisecond)
	defer cancel()

	go mr.Start(ctx)

	time.Sleep(30 * time.Millisecond)

	latest := mr.GetLatest()
	if latest == nil {
		t.Fatal("Expected reading from mock reader")
	}
	if latest.Weight != 1.250 {
		t.Errorf("Expected 1.250, got %v", latest.Weight)
	}
}
