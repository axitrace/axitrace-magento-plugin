// Mock AxiTrace ingestion endpoint for E2E tests.
//
// Captures every POST /magento/pixel body in memory, exposes the captured
// list via GET /captured-events for test assertions. Returns 202 with a
// minimal JSON body so the Magento module's IngestionApiClient sees a
// successful response.
//
// Not a security-sensitive component — runs only inside the docker-compose
// test network.
package main

import (
	"encoding/json"
	"io"
	"log"
	"net/http"
	"sync"
	"time"
)

type capturedEvent struct {
	ReceivedAt time.Time              `json:"received_at"`
	Path       string                 `json:"path"`
	Headers    map[string]string      `json:"headers"`
	Body       map[string]interface{} `json:"body"`
}

var (
	captured   []capturedEvent
	capturedMu sync.Mutex
)

func main() {
	mux := http.NewServeMux()
	mux.HandleFunc("/magento/pixel", handlePixel)
	mux.HandleFunc("/captured-events", handleCaptured)
	mux.HandleFunc("/api/public/workspace/tracking-domain", handleTrackingDomain)
	mux.HandleFunc("/_reset", handleReset)
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	})

	srv := &http.Server{
		Addr:              ":9999",
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
	}
	log.Println("mock-ingestion: listening on :9999")
	if err := srv.ListenAndServe(); err != nil {
		log.Fatalf("mock-ingestion: %v", err)
	}
}

func handlePixel(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	body, err := io.ReadAll(r.Body)
	if err != nil {
		http.Error(w, "read error", http.StatusBadRequest)
		return
	}
	var decoded map[string]interface{}
	_ = json.Unmarshal(body, &decoded)

	headers := map[string]string{}
	for k, v := range r.Header {
		if len(v) > 0 {
			headers[k] = v[0]
		}
	}

	capturedMu.Lock()
	captured = append(captured, capturedEvent{
		ReceivedAt: time.Now().UTC(),
		Path:       r.URL.Path,
		Headers:    headers,
		Body:       decoded,
	})
	capturedMu.Unlock()

	eventID, _ := decoded["eventSalt"].(string)
	if eventID == "" {
		eventID = "mock-no-event-id"
	}

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusAccepted)
	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"success": true,
		"eventId": eventID,
	})
}

func handleCaptured(w http.ResponseWriter, r *http.Request) {
	capturedMu.Lock()
	defer capturedMu.Unlock()
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(captured)
}

func handleTrackingDomain(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"success":    true,
		"domain":     "stat.magento.test",
		"verified":   true,
		"ssl_active": false,
	})
}

func handleReset(w http.ResponseWriter, r *http.Request) {
	capturedMu.Lock()
	captured = nil
	capturedMu.Unlock()
	w.WriteHeader(http.StatusNoContent)
}
