<?php

namespace App\Enums;

/**
 * Lifecycle of an invoice as it moves through the audit pipeline.
 *
 * Note: the column itself is a plain string, so legacy values written by the
 * Phase 2 demo seeder ("imported") remain readable; the enum documents the
 * states the pipeline produces and consumes.
 */
enum InvoiceStatus: string
{
    case Uploaded = 'uploaded';
    case Parsing = 'parsing';
    case Normalized = 'normalized';
    case Auditing = 'auditing';
    case Audited = 'audited';
    case Failed = 'failed';
}
