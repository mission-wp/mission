import { useRef, useEffect } from '@wordpress/element';

/**
 * HTML code editor powered by WordPress's built-in CodeMirror.
 *
 * Falls back to a plain textarea if CodeMirror isn't available.
 * @param {Object}   root0
 * @param {string}   root0.value
 * @param {Function} root0.onChange
 * @param {boolean}  root0.visible
 */
export default function HtmlCodeEditor( { value, onChange, visible } ) {
  const textareaRef = useRef( null );
  const editorRef = useRef( null );
  const onChangeRef = useRef( onChange );

  // Keep the callback ref current without reinitializing CodeMirror.
  useEffect( () => {
    onChangeRef.current = onChange;
  }, [ onChange ] );

  // Initialize CodeMirror once.
  useEffect( () => {
    const textarea = textareaRef.current;
    if ( ! textarea ) {
      return;
    }

    const settings = window.missiondpCodeEditor;
    const wpCodeEditor = window.wp?.codeEditor;

    if ( ! settings || ! wpCodeEditor ) {
      return;
    }

    const instance = wpCodeEditor.initialize( textarea, {
      ...settings,
      codemirror: {
        ...settings.codemirror,
        lineNumbers: true,
        lineWrapping: true,
        indentUnit: 2,
        tabSize: 2,
        indentWithTabs: false,
      },
    } );

    const cm = instance.codemirror;
    editorRef.current = cm;

    cm.on( 'change', () => {
      onChangeRef.current( cm.getValue() );
    } );

    return () => {
      cm.toTextArea();
      editorRef.current = null;
    };
  }, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- Intentionally run once on mount.

  // Sync external value changes into CodeMirror (e.g. reset to default).
  useEffect( () => {
    const cm = editorRef.current;
    if ( cm && cm.getValue() !== value ) {
      cm.setValue( value );
    }
  }, [ value ] );

  // Refresh CodeMirror when it becomes visible (fixes rendering issues).
  useEffect( () => {
    if ( visible && editorRef.current ) {
      // CodeMirror needs a tick to measure its container after display change.
      window.requestAnimationFrame( () => {
        editorRef.current.refresh();
      } );
    }
  }, [ visible ] );

  return (
    <div
      className="mission-email-editor__code-editor-wrap"
      style={ { display: visible ? 'block' : 'none' } }
    >
      <textarea
        ref={ textareaRef }
        className="mission-email-editor__html-editor"
        defaultValue={ value }
        onChange={ ( e ) => onChange( e.target.value ) }
      />
    </div>
  );
}
