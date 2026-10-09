-- Petra — développé par BELEJ-CONSULTING.
-- Ajout seul, garanti par la base : aucune opération enregistrée n'est modifiée ni supprimée.
-- Une correction est une nouvelle ligne (corrects_id) approuvée par un second utilisateur.
CREATE TRIGGER trg_prices_no_update BEFORE UPDATE ON prices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'prices: ajout seul, modification interdite';
CREATE TRIGGER trg_prices_no_delete BEFORE DELETE ON prices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'prices: ajout seul, suppression interdite';
CREATE TRIGGER trg_exchange_rates_no_update BEFORE UPDATE ON exchange_rates FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exchange_rates: ajout seul, modification interdite';
CREATE TRIGGER trg_exchange_rates_no_delete BEFORE DELETE ON exchange_rates FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exchange_rates: ajout seul, suppression interdite';
CREATE TRIGGER trg_shift_segments_no_update BEFORE UPDATE ON shift_segments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'shift_segments: ajout seul, modification interdite';
CREATE TRIGGER trg_shift_segments_no_delete BEFORE DELETE ON shift_segments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'shift_segments: ajout seul, suppression interdite';
CREATE TRIGGER trg_meter_readings_no_update BEFORE UPDATE ON meter_readings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'meter_readings: ajout seul, modification interdite';
CREATE TRIGGER trg_meter_readings_no_delete BEFORE DELETE ON meter_readings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'meter_readings: ajout seul, suppression interdite';
CREATE TRIGGER trg_cash_handovers_no_update BEFORE UPDATE ON cash_handovers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cash_handovers: ajout seul, modification interdite';
CREATE TRIGGER trg_cash_handovers_no_delete BEFORE DELETE ON cash_handovers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cash_handovers: ajout seul, suppression interdite';
CREATE TRIGGER trg_non_sale_outputs_no_update BEFORE UPDATE ON non_sale_outputs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'non_sale_outputs: ajout seul, modification interdite';
CREATE TRIGGER trg_non_sale_outputs_no_delete BEFORE DELETE ON non_sale_outputs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'non_sale_outputs: ajout seul, suppression interdite';
CREATE TRIGGER trg_credit_sales_no_update BEFORE UPDATE ON credit_sales FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'credit_sales: ajout seul, modification interdite';
CREATE TRIGGER trg_credit_sales_no_delete BEFORE DELETE ON credit_sales FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'credit_sales: ajout seul, suppression interdite';
CREATE TRIGGER trg_cash_expenses_no_update BEFORE UPDATE ON cash_expenses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cash_expenses: ajout seul, modification interdite';
CREATE TRIGGER trg_cash_expenses_no_delete BEFORE DELETE ON cash_expenses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cash_expenses: ajout seul, suppression interdite';
CREATE TRIGGER trg_tank_dips_no_update BEFORE UPDATE ON tank_dips FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tank_dips: ajout seul, modification interdite';
CREATE TRIGGER trg_tank_dips_no_delete BEFORE DELETE ON tank_dips FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tank_dips: ajout seul, suppression interdite';
CREATE TRIGGER trg_deliveries_no_update BEFORE UPDATE ON deliveries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'deliveries: ajout seul, modification interdite';
CREATE TRIGGER trg_deliveries_no_delete BEFORE DELETE ON deliveries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'deliveries: ajout seul, suppression interdite';
CREATE TRIGGER trg_audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log: ajout seul, modification interdite';
CREATE TRIGGER trg_audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log: ajout seul, suppression interdite';

-- Un poste évolue (ouvert → clôturé → validé) mais n'est jamais supprimé, ni modifié une fois validé.
CREATE TRIGGER trg_shifts_no_delete BEFORE DELETE ON shifts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'shifts: suppression interdite';
CREATE TRIGGER trg_shifts_validated_locked BEFORE UPDATE ON shifts FOR EACH ROW IF OLD.status = 'validated' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'shifts: poste valide, modification interdite'; END IF;
