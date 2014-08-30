# RFC6455 - The WebSocket Protocol

This library is meant to be a protocol handler for the RFC6455 specification.

---

### A rough roadmap

* v0.1 is the initial split from Ratchet/v0.3.2 as-is. In this state it currently relies on some of Ratchet's interfaces.
* v0.2 will be more framework agnostic and will not require any interfaces from Ratchet. A dependency on Guzzle (or hopefully PSR-7) may be required.
* v0.3 will look into performance tuning. No more expected exceptions.
* v0.4 extension support
* v1.0 when all the bases are covered
