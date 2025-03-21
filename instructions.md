# WP Fusion AI Development Guidelines

## Project Overview
WP Fusion is a WordPress plugin that connects websites to CRMs and marketing automation tools. It supports dozens of CRMs and 100+ WordPress plugins through its integration system.

## Development Standards

### Code Organization
- Main plugin file: `wp-fusion.php`
- Integrations: `includes/integrations/`
- CRM handlers: `includes/crm/`
- Core functionality: `includes/`
- Assets: `assets/`
- Tests: `tests/`

### Coding Standards
- Follow WordPress Coding Standards (WPCS) as defined in `.cursor/rules/wordpress.mdc`
- PHPStan for static analysis
- PHPCS for code style enforcement
- Use type hints and PHPDoc blocks for all new code
- Maintain PHPStan baseline in `phpstan-baseline.neon`
- Maintain PHPCS baseline in `phpcs.baseline.xml`

### Version Control
- Branch naming: `feature/`, `fix/`, `enhancement/` prefixes
- Commit messages should reference ticket numbers when applicable
- Keep commits atomic and focused
- Update version numbers in:
  - `wp-fusion.php`
  - `readme.txt`
  - Constant `WP_FUSION_VERSION`

### Testing
- PHPUnit for unit testing
- Test new CRM integrations thoroughly
- Test against supported WordPress versions (latest - 2)
- Test against supported WooCommerce versions when applicable

## AI Assistant Guidelines

### General Approach
1. Always check existing integration patterns before creating new ones
2. Prioritize maintaining backward compatibility
3. Follow established hook naming conventions
4. Use existing helper functions and utilities

### Code Generation
When asking AI to generate code:

1. **Integration Development**
```php
class WPF_{CRM_Name} extends WPF_CRM_Base {
    // Start with required abstract methods
    // Add CRM-specific implementations
}
```

2. **Hook Usage**
```php
// Filters
apply_filters( 'wpf_[context]_[action]', $value, $args );

// Actions
do_action( 'wpf_[context]_[action]', $args );
```

3. **API Interactions**
```php
// Use built-in HTTP methods
$response = wp_remote_post( $url, $args );
wpf_log( 'info', $user_id, 'API call to: ' . $url );
```

4. **Storing and Retrieving Options**
```php
// Store options
wp_fusion()->settings->set( 'option_name', $value );

// Retrieve options
$value = wpf_get_option( 'option_name', $default = false );
```

### Common Tasks

#### Adding New CRM Support
1. Create new class in `includes/crm/`
2. Implement required abstract methods
3. Add to CRM registration
4. Create API connection tests
5. Document supported features

#### Creating Integrations
1. Check `includes/integrations/` for similar plugins
2. Use standard integration class structure
3. Register filters for field sync
4. Add admin settings if needed

### Security Practices
- Sanitize all inputs
- Validate API responses
- Use nonces for admin actions
- Follow WordPress security best practices
- Never store sensitive data unencrypted

## Documentation Standards

### Code Comments
- Use PHPDoc blocks for classes and methods
- Document filter/action hooks
- Explain complex logic
- Mark deprecated functions

### Commit Messages
```
type(scope): description

[optional body]

[optional footer]
```

### Pull Request Template
- Description of changes
- Testing instructions
- Screenshots if UI changes
- Related issues/tickets

## AI Development Workflow

1. **Analysis Phase**
   - Review existing code patterns
   - Check integration examples
   - Verify dependencies
   - Initialize/update `.cursor/scratchpad.md` with task details

2. **Scratchpad Management**
   - Location: Always use `.cursor/scratchpad.md`
   - Format:
     ```
     current_task: "Task description"
     status: in_progress|complete|blocked
     steps:
     [ ] Step 1
     [X] Completed step
     reflections:
     - Key learnings
     - Potential improvements
     - Technical considerations
     ```
   - Update status and steps as work progresses
   - Document blockers and solutions
   - Track important decisions

3. **Implementation Phase**
   - Generate code following standards
   - Add proper error handling
   - Include logging statements
   - Add type hints and documentation

4. **Review Phase**
   - Code style compliance
   - Security review
   - Documentation completeness
   - Performance optimization

## Troubleshooting Guide for AI

1. **API Issues**
   - Check API credentials
   - Verify endpoint URLs
   - Review rate limits
   - Check for SSL issues

2. **Integration Problems**
   - Verify plugin compatibility
   - Check hook priorities
   - Review field mappings
   - Check for conflicts

3. **Performance Issues**
   - Review query optimization
   - Check caching implementation
   - Verify async operations
   - Monitor memory usage

## Resources

### Internal
- `readme.txt` - Plugin documentation
- `includes/crms/class-base.php` - Base CRM class
- `includes/integrations/class-base.php` - Base integration class

### External
- [WP Fusion Documentation](https://wpfusion.com/documentation/)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- [WordPress Documentation Standards](https://developer.wordpress.org/coding-standards/inline-documentation-standards/php/) 