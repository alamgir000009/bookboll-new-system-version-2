@include('emails.header')
<style>
    .exception-container {
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        margin: 20px 0;
        overflow: hidden;
        border: 1px solid #e0e0e0;
    }

    .alert-header {
        background: linear-gradient(135deg, #dc3545, #c82333);
        color: white;
        padding: 20px;
        text-align: center;
    }

    .alert-header h2 {
        margin: 0;
        font-size: 22px;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .alert-icon {
        font-size: 28px;
    }

    .info-section {
        padding: 20px;
        background: #f8f9fa;
        border-bottom: 1px solid #e0e0e0;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-top: 15px;
    }

    .info-item {
        background: white;
        padding: 12px;
        border-radius: 8px;
        border-left: 4px solid #dc3545;
    }

    .info-label {
        font-size: 11px;
        text-transform: uppercase;
        color: #6c757d;
        font-weight: 600;
        letter-spacing: 0.5px;
        margin-bottom: 5px;
    }

    .info-value {
        font-size: 14px;
        color: #212529;
        font-weight: 500;
        word-break: break-word;
    }

    .exception-details {
        padding: 20px;
    }

    .detail-section {
        margin-bottom: 25px;
    }

    .detail-section:last-child {
        margin-bottom: 0;
    }

    .section-title {
        font-size: 16px;
        font-weight: 600;
        color: #dc3545;
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 2px solid #dc3545;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .detail-item {
        display: flex;
        padding: 10px 0;
        border-bottom: 1px solid #f0f0f0;
    }

    .detail-item:last-child {
        border-bottom: none;
    }

    .detail-label {
        font-weight: 600;
        color: #495057;
        font-size: 13px;
        min-width: 140px;
        flex-shrink: 0;
    }

    .detail-value {
        color: #212529;
        font-size: 13px;
        flex: 1;
        word-break: break-word;
        font-family: 'Courier New', monospace;
        background: #f8f9fa;
        padding: 8px 12px;
        border-radius: 4px;
        border-left: 3px solid #dc3545;
    }

    .stack-trace {
        background: #1e1e1e;
        color: #d4d4d4;
        padding: 15px;
        border-radius: 8px;
        font-family: 'Courier New', monospace;
        font-size: 12px;
        line-height: 1.6;
        overflow-x: auto;
        max-height: 400px;
        overflow-y: auto;
    }

    .stack-trace-line {
        margin: 4px 0;
        padding: 2px 0;
    }

    .stack-trace-line.highlight {
        background: rgba(220, 53, 69, 0.2);
        padding: 4px 8px;
        border-left: 3px solid #dc3545;
    }

    .request-info {
        background: #e7f3ff;
        padding: 15px;
        border-radius: 8px;
        border-left: 4px solid #007bff;
        margin-top: 15px;
    }

    .request-info-title {
        font-weight: 600;
        color: #007bff;
        margin-bottom: 10px;
        font-size: 14px;
    }

    .badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .badge-production {
        background: #dc3545;
        color: white;
    }

    .badge-staging {
        background: #ffc107;
        color: #212529;
    }

    .badge-local {
        background: #28a745;
        color: white;
    }

    .action-required {
        background: #fff3cd;
        border: 2px solid #ffc107;
        border-radius: 8px;
        padding: 15px;
        margin-top: 20px;
    }

    .action-required-title {
        font-weight: 600;
        color: #856404;
        margin-bottom: 8px;
        font-size: 14px;
    }

    .action-required-text {
        color: #856404;
        font-size: 13px;
        margin: 0;
    }

    @media only screen and (max-width: 600px) {
        .info-grid {
            grid-template-columns: 1fr;
        }

        .detail-item {
            flex-direction: column;
        }

        .detail-label {
            min-width: auto;
            margin-bottom: 5px;
        }

        .detail-value {
            border-left: none;
            border-top: 3px solid #dc3545;
            padding-top: 8px;
        }
    }
</style>

<div class="content">
    <div class="exception-container">
        <div class="alert-header">
            <h2>
                <span class="alert-icon">🚨</span>
                Exception Error Detected
            </h2>
        </div>

        <div class="info-section">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Environment</div>
                    <div class="info-value">
                        <span class="badge badge-{{ strtolower($environment) }}">
                            {{ strtoupper($environment) }}
                        </span>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Application</div>
                    <div class="info-value">{{ $appName }}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Time</div>
                    <div class="info-value">{{ now()->format('M d, Y H:i:s T') }}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">URL</div>
                    <div class="info-value">{{ $appUrl }}</div>
                </div>
            </div>
        </div>

        <div class="exception-details">
            <div class="detail-section">
                <div class="section-title">
                    <span>⚠️</span>
                    Exception Information
                </div>
                <div class="detail-item">
                    <div class="detail-label">Exception Type:</div>
                    <div class="detail-value">{{ get_class($exception) }}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Message:</div>
                    <div class="detail-value">{{ $exception->getMessage() }}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">File:</div>
                    <div class="detail-value">{{ $exception->getFile() }}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Line:</div>
                    <div class="detail-value">{{ $exception->getLine() }}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Code:</div>
                    <div class="detail-value">{{ $exception->getCode() }}</div>
                </div>
            </div>

            @if(request())
            <div class="detail-section">
                <div class="section-title">
                    <span>🌐</span>
                    Request Information
                </div>
                <div class="request-info">
                    <div class="request-info-title">Request Details</div>
                    <div class="detail-item">
                        <div class="detail-label">Method:</div>
                        <div class="detail-value">{{ request()->method() }}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">URL:</div>
                        <div class="detail-value">{{ request()->fullUrl() }}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">IP Address:</div>
                        <div class="detail-value">{{ request()->ip() }}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">User Agent:</div>
                        <div class="detail-value">{{ request()->userAgent() }}</div>
                    </div>
                    @if(auth()->check())
                    <div class="detail-item">
                        <div class="detail-label">User:</div>
                        <div class="detail-value">{{ auth()->user()->name }} (ID: {{ auth()->user()->id }})</div>
                    </div>
                    @endif
                </div>
            </div>
            @endif

            @if(!empty($context))
            <div class="detail-section">
                <div class="section-title">
                    <span>📋</span>
                    Additional Context
                </div>
                @foreach($context as $key => $value)
                <div class="detail-item">
                    <div class="detail-label">{{ ucfirst(str_replace('_', ' ', $key)) }}:</div>
                    <div class="detail-value">
                        @if(is_array($value) || is_object($value))
                            <pre style="margin:0; white-space: pre-wrap;">{{ json_encode($value, JSON_PRETTY_PRINT) }}</pre>
                        @else
                            {{ $value }}
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
            @endif

            <div class="detail-section">
                <div class="section-title">
                    <span>📚</span>
                    Stack Trace
                </div>
                <div class="stack-trace">
                    @foreach($exception->getTrace() as $index => $trace)
                    <div class="stack-trace-line {{ $index === 0 ? 'highlight' : '' }}">
                        #{{ $index }}
                        @if(isset($trace['file']))
                            {{ $trace['file'] }}({{ $trace['line'] ?? 'N/A' }})
                        @else
                            [Internal]
                        @endif
                        @if(isset($trace['class']))
                            : {{ $trace['class'] }}{{ $trace['type'] ?? '->' }}{{ $trace['function'] ?? 'N/A' }}()
                        @elseif(isset($trace['function']))
                            : {{ $trace['function'] }}()
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="action-required">
            <div class="action-required-title">⚠️ Action Required</div>
            <p class="action-required-text">
                An exception has occurred in your application. Please review the details above and take appropriate action to resolve the issue.
            </p>
        </div>
    </div>
</div>

@include('emails.footer')

