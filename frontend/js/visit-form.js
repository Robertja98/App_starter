/**
 * Visit Form Handler
 * 
 * Manages the main service visit form workflow:
 * - Select site/equipment
 * - Record measurements
 * - Log consumables
 * - Capture photos
 * - Add repair recommendations
 * - Customer sign-off
 * - Submit or queue
 */

class VisitForm {
    constructor() {
        this.currentVisitId = null;
        this.formData = {};
        this.init();
    }

    /**
     * Initialize form listeners and UI.
     */
    init() {
        console.log('VisitForm initialized');
        this.setupFormListeners();
    }

    /**
     * Setup event listeners for form actions.
     */
    setupFormListeners() {
        // Form submission
        document.addEventListener('submit', (e) => {
            if (e.target.id === 'service-visit-form') {
                e.preventDefault();
                this.submitVisit(e.target);
            }
        });

        // Photo capture
        document.addEventListener('change', (e) => {
            if (e.target.type === 'file' && e.target.name === 'media') {
                this.handlePhotoCapture(e.target);
            }
        });
    }

    /**
     * Load visit form UI.
     */
    async loadVisitForm(visitId = null) {
        this.currentVisitId = visitId;

        const html = `
            <div class="card">
                <h2 class="card-title">Service Visit</h2>
                <form id="service-visit-form">
                    <input type="hidden" name="csrf_token" id="csrf-token">

                    <div class="form-group">
                        <label for="site">Site</label>
                        <select name="site_id" id="site" required>
                            <option value="">-- Select Site --</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="equipment">Equipment</label>
                        <select name="equipment_id" id="equipment" required>
                            <option value="">-- Select Equipment --</option>
                        </select>
                    </div>

                    <fieldset>
                        <legend>Chemical Measurements</legend>
                        <div id="measurements-container"></div>
                        <button type="button" class="btn-secondary" id="add-measurement">+ Add Measurement</button>
                    </fieldset>

                    <fieldset>
                        <legend>Consumables Replaced</legend>
                        <div id="consumables-container"></div>
                        <button type="button" class="btn-secondary" id="add-consumable">+ Add Consumable</button>
                    </fieldset>

                    <fieldset>
                        <legend>Media & Photos</legend>
                        <div id="media-container">
                            <input type="file" name="media" multiple accept="image/*" capture="environment">
                            <div id="uploaded-media-list"></div>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend>Repair Recommendations</legend>
                        <div id="repairs-container"></div>
                        <button type="button" class="btn-secondary" id="add-repair">+ Add Recommendation</button>
                    </fieldset>

                    <div class="form-group">
                        <label for="narrative">Service Notes / Narrative</label>
                        <textarea name="narrative" id="narrative" placeholder="Describe the service visit, any observations, customer feedback..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="signature">Customer Signature</label>
                        <canvas id="signature-canvas" width="400" height="120" style="border: 1px solid #bdc3c7;"></canvas>
                        <button type="button" class="btn-secondary" id="clear-signature">Clear</button>
                    </div>

                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" class="btn-primary">Submit Visit</button>
                        <button type="button" class="btn-secondary" id="save-draft">Save as Draft</button>
                        <button type="button" class="btn-secondary" id="cancel-form">Cancel</button>
                    </div>
                </form>
            </div>
        `;

        const pageContainer = document.getElementById('page-container');
        if (pageContainer) {
            pageContainer.innerHTML = html;
            this.setupFormControls();
            await this.loadSites();
        }
    }

    /**
     * Setup form control interactions.
     */
    setupFormControls() {
        // Add measurement field
        document.getElementById('add-measurement')?.addEventListener('click', () => {
            this.addMeasurementField();
        });

        // Add consumable field
        document.getElementById('add-consumable')?.addEventListener('click', () => {
            this.addConsumableField();
        });

        // Add repair field
        document.getElementById('add-repair')?.addEventListener('click', () => {
            this.addRepairField();
        });

        // Clear signature
        document.getElementById('clear-signature')?.addEventListener('click', () => {
            this.clearSignature();
        });

        // Save draft
        document.getElementById('save-draft')?.addEventListener('click', () => {
            this.saveDraft();
        });

        // Cancel
        document.getElementById('cancel-form')?.addEventListener('click', () => {
            if (confirm('Discard this visit?')) {
                window.location.reload();
            }
        });

        // Signature canvas
        this.setupSignatureCapture();
    }

    /**
     * Add a measurement input field.
     */
    addMeasurementField() {
        const container = document.getElementById('measurements-container');
        if (!container) return;

        const id = `measurement-${Date.now()}`;
        const html = `
            <div class="form-group" id="${id}">
                <label>
                    <input type="text" name="measurement_type[]" placeholder="e.g., pH, Chlorine" required>
                    <input type="number" name="measurement_value[]" step="0.01" placeholder="Value" required>
                    <input type="text" name="measurement_unit[]" placeholder="Unit (ppm, psi, etc)" required>
                    <select name="measurement_status[]">
                        <option value="normal">Normal</option>
                        <option value="warning">Warning</option>
                        <option value="critical">Critical</option>
                    </select>
                    <button type="button" class="btn-danger" onclick="document.getElementById('${id}').remove()">Remove</button>
                </label>
            </div>
        `;

        container.insertAdjacentHTML('beforeend', html);
    }

    /**
     * Add a consumable input field.
     */
    addConsumableField() {
        const container = document.getElementById('consumables-container');
        if (!container) return;

        const id = `consumable-${Date.now()}`;
        const html = `
            <div class="form-group" id="${id}">
                <label>
                    <input type="text" name="consumable_name[]" placeholder="e.g., Filter Cartridge" required>
                    <input type="number" name="consumable_qty[]" step="0.1" placeholder="Quantity" required>
                    <input type="text" name="consumable_unit[]" placeholder="Unit (units, kg, etc)" required>
                    <input type="text" name="consumable_reason[]" placeholder="Reason for replacement">
                    <button type="button" class="btn-danger" onclick="document.getElementById('${id}').remove()">Remove</button>
                </label>
            </div>
        `;

        container.insertAdjacentHTML('beforeend', html);
    }

    /**
     * Add a repair recommendation field.
     */
    addRepairField() {
        const container = document.getElementById('repairs-container');
        if (!container) return;

        const id = `repair-${Date.now()}`;
        const html = `
            <div class="form-group" id="${id}">
                <label>Issue & Recommendation</label>
                <textarea name="repair_issue[]" placeholder="Describe the issue..." required></textarea>
                <textarea name="repair_recommendation[]" placeholder="Recommended fix..."></textarea>
                <select name="repair_priority[]">
                    <option value="low">Low Priority</option>
                    <option value="medium" selected>Medium Priority</option>
                    <option value="high">High Priority</option>
                    <option value="urgent">Urgent</option>
                </select>
                <input type="number" name="repair_cost[]" placeholder="Est. Cost ($)" step="0.01">
                <button type="button" class="btn-danger" onclick="document.getElementById('${id}').remove()">Remove</button>
            </div>
        `;

        container.insertAdjacentHTML('beforeend', html);
    }

    /**
     * Setup signature capture canvas.
     */
    setupSignatureCapture() {
        const canvas = document.getElementById('signature-canvas');
        if (!canvas) return;

        let isDrawing = false;
        const ctx = canvas.getContext('2d');
        ctx.strokeStyle = '#000';
        ctx.lineWidth = 2;

        canvas.addEventListener('touchstart', (e) => {
            isDrawing = true;
            const touch = e.touches[0];
            const rect = canvas.getBoundingClientRect();
            ctx.beginPath();
            ctx.moveTo(touch.clientX - rect.left, touch.clientY - rect.top);
        });

        canvas.addEventListener('touchmove', (e) => {
            if (!isDrawing) return;
            const touch = e.touches[0];
            const rect = canvas.getBoundingClientRect();
            ctx.lineTo(touch.clientX - rect.left, touch.clientY - rect.top);
            ctx.stroke();
        });

        canvas.addEventListener('touchend', () => {
            isDrawing = false;
        });
    }

    /**
     * Clear signature canvas.
     */
    clearSignature() {
        const canvas = document.getElementById('signature-canvas');
        if (canvas) {
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }
    }

    /**
     * Handle photo capture.
     */
    async handlePhotoCapture(input) {
        if (!window.syncQueue) {
            console.error('SyncQueue not available');
            return;
        }

        for (const file of input.files) {
            const queued = await window.syncQueue.queueMedia(file, this.currentVisitId);
            if (queued) {
                console.log('Photo queued:', file.name);
            }
        }
    }

    /**
     * Load sites for dropdown.
     */
    async loadSites() {
        try {
            const response = await fetch('/api/sites');
            const data = await response.json();

            const select = document.getElementById('site');
            if (select && data.sites) {
                data.sites.forEach((site) => {
                    const option = document.createElement('option');
                    option.value = site.id;
                    option.textContent = site.site_name;
                    select.appendChild(option);
                });

                // Load equipment when site changes
                select.addEventListener('change', () => this.loadEquipment(select.value));
            }
        } catch (error) {
            console.error('Failed to load sites:', error);
        }
    }

    /**
     * Load equipment for selected site.
     */
    async loadEquipment(siteId) {
        try {
            const response = await fetch(`/api/equipment?site_id=${siteId}`);
            const data = await response.json();

            const select = document.getElementById('equipment');
            if (select) {
                select.innerHTML = '<option value="">-- Select Equipment --</option>';
                if (data.equipment) {
                    data.equipment.forEach((item) => {
                        const option = document.createElement('option');
                        option.value = item.id;
                        option.textContent = `${item.equipment_type} - ${item.model}`;
                        select.appendChild(option);
                    });
                }
            }
        } catch (error) {
            console.error('Failed to load equipment:', error);
        }
    }

    /**
     * Submit the visit form.
     */
    async submitVisit(form) {
        const formData = new FormData(form);
        const visitData = Object.fromEntries(formData);

        if (window.syncQueue) {
            const queued = await window.syncQueue.queueSubmission(visitData);
            if (queued) {
                alert('Visit saved. It will sync when online.');
                form.reset();
            }
        }
    }

    /**
     * Save visit as draft to local storage.
     */
    saveDraft() {
        const form = document.getElementById('service-visit-form');
        const formData = new FormData(form);
        const draft = Object.fromEntries(formData);

        localStorage.setItem('visit_draft', JSON.stringify({
            data: draft,
            timestamp: new Date().toISOString(),
        }));

        alert('Draft saved locally.');
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    window.visitForm = new VisitForm();
});
