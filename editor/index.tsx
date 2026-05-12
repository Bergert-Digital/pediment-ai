import { registerPlugin } from '@wordpress/plugins';
import DocumentPanel from './DocumentPanel';
import BlockChatPanel from './BlockChatPanel';
import './styles.scss';

registerPlugin('starter-ai-document-panel', { render: DocumentPanel });
registerPlugin('starter-ai-block-chat',     { render: BlockChatPanel });
