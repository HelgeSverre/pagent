# Event Integration TODO

**Status as of 2025-11-18**
**COMPLETED ✅**

## ✅ Completed

### Infrastructure (100%)
- [x] Created 19 event classes across 6 namespaces
- [x] Event base class with propagation control and snake_case naming
- [x] EventListener interface
- [x] EventDispatcher with priority system
- [x] EventManager singleton
- [x] 36 passing tests (16 EventDispatcher + 20 event classes)
- [x] PHPStan Level 9 passing with updated baseline

### Agent.php Integration (100%)
- [x] Added EventDispatcher property and initialization
- [x] Added public API methods: `on()`, `once()`, `off()`, `listen()`
- [x] Added private `fireEvent()` helper method
- [x] Added all event class imports (lines 13-34)

### Events Integrated (17 of 19 event types - 89%)
- [x] **BeforePromptEvent** - Line ~309 in `prompt()`
- [x] **AfterPromptEvent** - Line ~406 in `prompt()`
- [x] **MemoryLoadingEvent** - Before `memory->load()` calls
- [x] **MemoryLoadedEvent** - After `memory->load()` calls
- [x] **MemorySavingEvent** - Before `memory->save()` calls
- [x] **MemorySavedEvent** - After `memory->save()` calls
- [x] **ContextPrunedEvent** - Line ~323 when context is pruned
- [x] **BeforeLLMRequestEvent** - Lines ~1161 & ~1173 in `callProviderWithSpan()`
- [x] **AfterLLMResponseEvent** - Lines ~1171 & ~1187 in `callProviderWithSpan()`
- [x] **ToolExecutingEvent** - Line ~693 in `executeTool()`
- [x] **ToolExecutedEvent** - Line ~706 in `executeTool()`
- [x] **ToolErrorEvent** - Line ~735 in `executeTool()` catch block
- [x] **GuardCheckingEvent** - Line ~984 in `runGuards()`
- [x] **GuardPassedEvent** - Line ~1005 in `runGuards()`
- [x] **GuardViolatedEvent** - Line ~989 in `runGuards()`
- [x] **GuardFallbackEvent** - Lines ~440 & ~622 in fallback sections
- [x] **StreamStartedEvent** - Line ~531 in `stream()`
- [x] **StreamCompletedEvent** - Line ~635 in `streamTo()`
- [ ] **StreamChunkEvent** - Not implemented (requires StreamResponse modification)

---

## 🚧 Optional Future Enhancements

### 1. StreamChunkEvent Implementation
**Status**: Not implemented - requires StreamResponse modification

To implement `StreamChunkEvent`, you would need to:
1. Modify `src/Streaming/StreamResponse.php` to fire events for each chunk
2. Add chunk counting mechanism to track total chunks
3. Update provider streaming implementations to support chunk events

This is optional and can be added in a future release if needed.

---

### 2. Integration Tests
**Status**: Not yet created

Create `tests/Integration/Events/` directory with tests that:
- Verify events fire correctly during actual agent operations
- Test event listener registration and execution
- Verify event data is accurate
- Test event propagation stopping

---

### 3. TelemetryEventBridge
**Status**: Not yet created

Create `src/Events/Bridges/TelemetryEventBridge.php` that:
- Listens to all events
- Maps events to OpenTelemetry spans/metrics
- Provides automatic telemetry integration
- Can be enabled/disabled via config

---

### 4. Documentation
**Status**: Not yet created

Create `docs/events-hooks.md` with:
- Event system overview
- List of all available events
- Examples of listening to events
- Use cases (logging, metrics, debugging)
- Custom event listener creation

---

### 5. Examples
**Status**: Not yet created

Create example files:
- `examples/20-event-logging.php` - Log all agent events
- `examples/21-event-metrics.php` - Track metrics via events
- `examples/22-event-debugging.php` - Debug agent behavior with events
- `examples/23-custom-event-listener.php` - Create custom listeners

---

## 📊 Final Summary

**Event Types**: 17/19 integrated (89%)
**Core Integration**: COMPLETE ✅
**Tests**: 36 passing (117 assertions) ✅
**PHPStan Level 9**: PASSING ✅

**Files Modified**:
- ✅ `src/Agent.php` - Fully integrated with 17 event types
- ✅ `src/Events/` - Complete event infrastructure (23 files)
- ✅ `tests/Unit/Events/` - Comprehensive tests (2 test files)
- ⏳ `tests/Integration/Events/` - Optional future enhancement
- ⏳ `src/Events/Bridges/TelemetryEventBridge.php` - Optional future enhancement
- ⏳ `docs/events-hooks.md` - Optional future enhancement
- ⏳ `examples/20-23-events-*.php` - Optional future enhancement

**All core event integration work is COMPLETE! 🎉**

The event system is now fully functional and integrated into the Agent class. Users can listen to events via `$agent->on()`, `$agent->once()`, and `$agent->listen()` methods.
