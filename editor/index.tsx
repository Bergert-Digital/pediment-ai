import { registerPlugin } from '@wordpress/plugins';
import DocumentPanel from './DocumentPanel';
import BlockChatPanel from './BlockChatPanel';
import './styles.scss';

registerPlugin('pediment-ai-document-panel', { render: DocumentPanel });
registerPlugin('pediment-ai-block-chat',     { render: BlockChatPanel });
